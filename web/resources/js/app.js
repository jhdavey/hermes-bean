import {
    commandAfterWakePhrase,
    normalizedVoiceCommand,
    realtimeSpokenAnswerAllowsBackgroundQueue,
    voiceCommandNeedsAgentWork,
    voiceCommandRequiresBackgroundWork,
    voiceCommandWantsDetailedChat,
    voiceCancelRequested,
} from './voiceWake.js';

const mount = document.getElementById('heybean-web-app');

if (mount) {
    const logoUrl = mount.dataset.logo || '/images/bean-logo.png';
    const initialMode = mount.dataset.authMode || 'login';
    const initialSelectedPlan = ['base', 'premium', 'pro'].includes(mount.dataset.selectedPlan) ? mount.dataset.selectedPlan : '';
    const initialBillingStatus = new URLSearchParams(window.location.search).get('billing') || '';
    const tokenKey = 'heybean.web.token';
    const rememberKey = 'heybean.web.remember';
    const activeWorkspaceKey = 'heybean.web.activeWorkspace';
    const dashboardChangeKey = 'heybean.dashboard.changeId';
    const dashboardDataCacheKey = 'heybean.dashboard.data';
    const kioskVoiceKey = 'heybean.kioskVoice';
    const calendarInitialWindowDays = 56;
    const calendarWindowChunkDays = 28;
    const appThemes = [
        { key: 'green', label: 'Green', accent: '#16a34a' },
        { key: 'gray', label: 'Gray', accent: '#64748b' },
        { key: 'blue', label: 'Blue', accent: '#2563eb' },
        { key: 'purple', label: 'Purple', accent: '#7c3aed' },
        { key: 'pink', label: 'Pink', accent: '#db2777' },
        { key: 'red', label: 'Red', accent: '#dc2626' },
        { key: 'orange', label: 'Orange', accent: '#ea580c' },
        { key: 'gold', label: 'Gold', accent: '#d97706' },
        { key: 'teal', label: 'Teal', accent: '#0d9488' },
        { key: 'indigo', label: 'Indigo', accent: '#4f46e5' },
    ];
    const appThemesByKey = new Map(appThemes.map((theme) => [theme.key, theme]));
    const subscriptionPlans = {
        base: {
            label: 'Base',
            price: '$4.99',
            summary: '2 workspaces, Bean chat and voice, connected calendar planning, push reminders, and recent context.',
            trial: 'Base 7-day free trial selected',
            bestFor: 'For getting your personal day into one organized place.',
            features: [
                'Tasks, reminders, calendar, chat, and voice',
                '2 workspaces and 1 connected calendar',
                'Push reminders and recent Bean context',
            ],
        },
        premium: {
            label: 'Premium',
            price: '$19.99',
            summary: '5 workspaces, expanded Bean capacity, email reminders, recurring routines, multiple calendars, and 1 year of history.',
            trial: 'Premium 7-day free trial selected',
            bestFor: 'Best for busy households and daily routines.',
            popular: true,
            features: [
                '5 workspaces for home, work, school, and projects',
                'Push and email reminders with recurring routines',
                'Multiple calendars and 1 year of history',
            ],
        },
        pro: {
            label: 'Pro',
            price: '$49.99',
            summary: 'Unlimited workspaces, maximum Bean capacity, unlimited connected accounts, full history, and priority background work.',
            trial: 'Pro 7-day free trial selected',
            bestFor: 'For running Bean across every part of life.',
            features: [
                'Unlimited workspaces and connected accounts',
                'Highest Bean usage and external tool budget',
                'Full memory, priority background work, priority support',
            ],
        },
    };

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
        palette: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22a10 10 0 1 1 8.8-5.2 2.7 2.7 0 0 1-2.4 1.4h-1.3a2 2 0 0 0-1.7 3.1c.1.2 0 .5-.3.6a10.7 10.7 0 0 1-3.1.1Z"/><circle cx="7.5" cy="10" r="1"/><circle cx="10.5" cy="6.5" r="1"/><circle cx="15" cy="7.5" r="1"/><circle cx="16.5" cy="12" r="1"/></svg>',
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
        selectedPlan: initialSelectedPlan,
        subscriptionSummary: null,
        subscriptionCheckoutStatus: new URLSearchParams(window.location.search).get('checkout') || '',
        billingCheckoutStatus: initialBillingStatus,
        billingPaymentMethod: null,
        billingPaymentLoading: false,
        billingBusy: false,
        billingMessage: '',
        billingError: '',
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
        settingsCategoryId: '',
        approvals: [],
        blockers: [],
        activity: [],
        adminUsage: null,
        adminPlanLimits: null,
        adminUsageLoading: false,
        adminModelRegistry: null,
        adminHermesStatus: null,
        adminHermesUpdating: false,
        adminUserGrowthRange: 'last_30_days',
        adminArchivedIssuesOpen: false,
        issueReportSubmitting: false,
        ttsPreviewing: false,
        googleStatus: null,
        googleAuthUrl: '',
        messages: [],
        session: null,
        chatSessions: [],
        chatHistoryOpen: false,
        chatRunState: 'Ready',
        beanWorkItems: [],
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
        onboardingTourActive: false,
        onboardingTourStep: 0,
        calendarRefreshing: false,
        taskFilter: 'active',
        reminderFilter: 'pending',
        dashboardChangeLastId: 0,
        pendingTaskUpserts: new Map(),
        pendingTaskDeletes: new Set(),
        pendingReminderUpserts: new Map(),
        pendingReminderDeletes: new Set(),
        expandedTaskIds: new Set(),
        futureTaskBucketsOpen: {
            seven: false,
            thirty: false,
        },
        pendingCalendarUpserts: new Map(),
        pendingCalendarDeletes: new Set(),
        busy: false,
        error: '',
        notice: '',
        modal: null,
    };

    applyAppTheme();

    let voiceHoldActive = false;
    let voiceHoldPressed = false;
    let voiceStartPending = false;
    let voiceSubmitOnEnd = false;
    let suppressNextSendClick = false;
    let mobileBeanHoldTimer = 0;
    let mobileBeanPointerId = null;
    let mobileBeanPressing = false;
    let mobileBeanHoldStarted = false;
    let mobileBeanClickSuppressed = false;
    let timelineDrag = null;
    let timelineSuppressClick = false;
    let dashboardChangeAbort = null;
    let dashboardChangeLoopActive = false;
    let dashboardRefreshTimer = 0;
    let adminCommandRunPollTimer = 0;
    let deferredDashboardRenderPending = false;
    let deferredDashboardRenderTimer = 0;
    let dashboardRefreshGeneration = 0;
    let localResourceSequence = -1;
    let kioskRecognition = null;
    let kioskBargeRecognition = null;
    let kioskRecognitionActive = false;
    let kioskBargeRecognitionActive = false;
    let kioskRecognitionShouldRestart = false;
    let kioskCommandText = '';
    let kioskConversationActive = false;
    let kioskIntentionalCancelActive = false;
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
    let kioskRealtimeCurrentUserTurn = null;
    let kioskRealtimeAssistantDraft = null;
    let kioskRealtimeSuppressNextAssistantPersist = false;
    let kioskRealtimeVoiceOnlyAssistant = false;
    let kioskRealtimeIgnoreNextFunctionCalls = false;
    let kioskRealtimeInputAudioContext = null;
    let kioskRealtimeInputAudioSource = null;
    let kioskRealtimeInputAnalyser = null;
    let kioskRealtimeInputMonitorFrame = 0;
    let kioskRealtimeInputActiveSince = 0;
    let kioskRealtimeInputQuietSince = 0;
    let kioskRealtimeInputLastActiveAt = 0;
    const kioskRealtimeUserTranscriptDrafts = new Map();
    let kioskRealtimeResponseTimer = 0;
    let kioskRealtimeToolFallbackTimer = 0;
    let kioskRealtimeToolFallbackContent = '';
    let kioskRealtimeReconnectTimer = 0;
    let kioskRealtimeReconnectAttempts = 0;
    let kioskRealtimeSuppressInputUntil = 0;
    let kioskRealtimeAssistantOutputStartedAt = 0;
    let kioskRealtimeAssistantOutputTimer = 0;
    let kioskRealtimeDeferredWorkingStatusTimer = 0;
    let kioskRealtimeBackgroundDeliveryTimer = 0;
    let kioskRealtimePendingBackgroundResult = null;
    let kioskRealtimePendingFunctionCalls = [];
    let kioskRealtimeBackgroundWorkActive = false;
    let kioskRealtimeAwaitingFollowup = false;
    let kioskRealtimeLastAssistantText = '';
    let kioskRealtimeLastAssistantOutputEndedAt = 0;
    let kioskRealtimeBackgroundProgressContext = null;
    let kioskRealtimeWakeContinuationUntil = 0;
    let kioskRealtimeResponseCreateSentAt = 0;
    let kioskRealtimeAwaitingFirstAudio = false;
    const kioskRealtimeBackgroundProgressTimers = new Set();
    const kioskRealtimeSpokenSegments = [];
    const kioskRealtimeMaxReconnectAttempts = 5;
    const kioskRealtimeConnectTimeoutMs = 15000;
    const kioskRealtimeTransientDisconnectMs = 12000;
    const kioskRealtimeTransientStatusMs = 2500;
    const kioskRealtimeTurnDebounceMs = 2200;
    const kioskRealtimeWakeContinuationMs = 5500;
    const kioskRealtimeProcessedCalls = new Set();
    const kioskRealtimeRunWatchTimers = new Map();
    const kioskRealtimeDeferredFunctionOutputTimers = new Set();
    let chatRequestCounter = 0;
    let activeChatRequestId = 0;
    let beanWorkEventPollTimer = 0;
    let beanWorkEventPollToken = 0;
    let beanWorkStatusClearTimer = 0;
    let beanWorkStatusHoldUntil = 0;
    let beanWorkStatusMinUntil = 0;
    const cancelledChatRequestIds = new Set();

    boot();
    bindResponsiveCalendar();
    bindCurrentTimeTicker();
    bindDashboardRealtimeFallbacks();
    bindDeferredDashboardRenderFlush();

    async function boot() {
        if (initialMode === 'subscribe') {
            await loadSubscriptionPage();
            return;
        }
        if (state.token) {
            await loadSignedIn();
        } else {
            state.phase = 'signedOut';
            render();
        }
    }

    async function loadSubscriptionPage() {
        state.phase = 'subscription';
        state.error = '';
        render();
        if (!state.token) {
            state.phase = 'signedOut';
            state.authMode = 'register';
            state.notice = '';
            state.error = '';
            render();
            return;
        }
        try {
            const [user, subscription] = await Promise.all([
                api('/auth/me'),
                api('/billing/subscription').catch(() => null),
            ]);
            state.user = user;
            state.subscriptionSummary = subscription;
            state.phase = 'subscription';
            state.error = '';
        } catch (error) {
            clearToken();
            state.phase = 'signedOut';
            state.authMode = 'login';
            state.error = friendlyError(error, 'load your subscription setup');
        }
        render();
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
                return true;
            } else if (['0', 'false', 'no', 'off'].includes(normalized)) {
                localStorage.removeItem(kioskVoiceKey);
                return false;
            }
        }
        localStorage.removeItem(kioskVoiceKey);
        return false;
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

    function normalizeThemeKey(value) {
        const key = String(value || '').trim().toLowerCase();
        return appThemesByKey.has(key) ? key : 'green';
    }

    function themeForKey(value) {
        return appThemesByKey.get(normalizeThemeKey(value)) || appThemes[0];
    }

    function currentThemeKey() {
        return normalizeThemeKey(state.user?.theme);
    }

    function applyAppTheme(value = currentThemeKey()) {
        const theme = themeForKey(value);
        document.body.dataset.hbTheme = theme.key;
        document.querySelector('meta[name="theme-color"]')?.setAttribute('content', theme.accent);
    }

    function themeAccentColor() {
        return themeForKey(currentThemeKey()).accent;
    }

    function themeSettingsMarkup() {
        const selectedTheme = currentThemeKey();
        const selected = themeForKey(selectedTheme);
        return `
            <div class="hb-surface-soft hb-card-pad hb-settings-section hb-theme-settings">
                ${settingsSectionHeader(icons.palette, 'Appearance', `${selected.label} accent`)}
                <div class="hb-theme-select-row">
                    <span class="hb-theme-swatch" style="--hb-theme-swatch: ${escapeAttr(selected.accent)}" aria-hidden="true"></span>
                    <label class="hb-label">Accent color
                        <select class="hb-select" data-theme-select aria-label="Accent color">
                            ${appThemes.map((theme) => `<option value="${escapeAttr(theme.key)}" ${theme.key === selectedTheme ? 'selected' : ''}>${escapeHtml(theme.label)}</option>`).join('')}
                        </select>
                    </label>
                </div>
            </div>
        `;
    }

    function settingsSectionHeader(icon, title, subtitle = '') {
        return `
            <div class="hb-settings-panel-head">
                <span class="hb-compact-icon">${icon}</span>
                <div>
                    <strong>${escapeHtml(title)}</strong>
                    ${subtitle ? `<small>${escapeHtml(subtitle)}</small>` : ''}
                </div>
            </div>`;
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
            restoreRememberedActiveWorkspace(user);
            state.dashboardChangeLastId = Number(localStorage.getItem(dashboardChangeStorageKey()) || 0);
            const cachedWorkspaceId = currentWorkspaceIdFromUser(state.user);
            if (cachedWorkspaceId && applyDashboardCache(cachedWorkspaceId)) {
                state.phase = 'signedIn';
                state.error = '';
                render();
            }

            const [summary, tasks, pastTasks, reminders, calendar, categories, googleStatus, subscription, billingPayment] = await Promise.all([
                recover(api(workspaceScopedPath('/today')), state.summary || {}),
                recover(api(workspaceScopedPath('/tasks')), state.tasks),
                recover(api(workspaceScopedPath('/tasks/past')), []),
                recover(api(workspaceScopedPath('/reminders')), state.reminders),
                recover(api(workspaceScopedPath('/calendar-events?skip_google_sync=1')), state.calendar),
                recover(api(workspaceScopedPath('/event-categories')), state.categories),
                api('/google-calendar/status?cached=1').catch(() => null),
                api('/billing/subscription').catch(() => state.subscriptionSummary),
                api('/billing/payment-method').catch(() => ({ payment_method: null })),
            ]);
            state.user = mergeUser(user, summary?.user, summary);
            state.summary = summary;
            state.subscriptionSummary = subscription || state.subscriptionSummary;
            state.billingPaymentMethod = billingPayment?.payment_method || billingPayment?.paymentMethod || null;
            setActiveWorkspaceLocally(currentWorkspaceId(), { persist: false });
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
            applyBillingReturnNotice();
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

    function activeWorkspaceStorageKey(user = state.user) {
        return `${activeWorkspaceKey}.${user?.id || 'anon'}`;
    }

    function rememberedActiveWorkspaceId(user = state.user) {
        try {
            return localStorage.getItem(activeWorkspaceStorageKey(user)) || '';
        } catch (_) {
            return '';
        }
    }

    function persistActiveWorkspaceId(workspaceId, user = state.user) {
        if (!workspaceId || !user?.id) return;
        try {
            localStorage.setItem(activeWorkspaceStorageKey(user), String(workspaceId));
        } catch (_) {
            // Active workspace persistence is best-effort; the server default remains the fallback.
        }
    }

    function workspaceIsAccessible(workspaceId, user = state.user) {
        return normalizeList(user?.workspaces || state.summary?.workspaces)
            .some((workspace) => String(workspace.id) === String(workspaceId));
    }

    function restoreRememberedActiveWorkspace(user = state.user) {
        const workspaceId = rememberedActiveWorkspaceId(user);
        if (!workspaceId || !workspaceIsAccessible(workspaceId, user)) return false;
        setActiveWorkspaceLocally(workspaceId, { persist: false });
        return true;
    }

    function workspaceScopedPath(path, workspaceId = currentWorkspaceId()) {
        if (!workspaceId) return path;
        const [base, query = ''] = String(path).split('?');
        const params = new URLSearchParams(query);
        params.set('workspace_id', workspaceId);
        const serialized = params.toString();
        return serialized ? `${base}?${serialized}` : base;
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

    function setActiveWorkspaceLocally(workspaceId, options = {}) {
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
        if (options.persist !== false) {
            persistActiveWorkspaceId(id);
        }
    }

    function syncSummaryAgentProfileFromUser(user = state.user) {
        const profile = currentAgentProfileFromUser(user);
        if (!profile) return;
        state.summary = {
            ...(state.summary || {}),
            agent_profile: profile,
            agentProfile: profile,
        };
    }

    function currentAgentProfile() {
        return currentAgentProfileFromUser(state.user)
            || state.summary?.agent_profile
            || state.summary?.agentProfile
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

    function profileHomeCity(profile = currentAgentProfile()) {
        const settings = profileSettings(profile);
        return settings.home_location
            || settings.homeLocation
            || settings.weather_location
            || settings.weatherLocation
            || settings.default_weather_location
            || settings.defaultWeatherLocation
            || settings.weather?.location
            || settings.memory?.user_preferences?.home_location
            || settings.memory?.userPreferences?.homeLocation
            || settings.memory?.user_preferences?.weather_location
            || settings.memory?.userPreferences?.weatherLocation
            || '';
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

    function userIsEarlyAccess() {
        return state.user?.is_early_access === true
            || state.user?.isEarlyAccess === true
            || Boolean(state.user?.early_access_signup || state.user?.earlyAccessSignup);
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
        applyAppTheme();
        const modalKey = state.modal ? modalIdentity(state.modal) : '';
        const existingModal = modalKey ? mount.querySelector('[data-modal-root]') : null;
        const preservedModal = existingModal?.dataset?.modalKey === modalKey ? existingModal : null;
        const preservedModalState = preservedModal ? captureModalDomState(preservedModal) : null;
        if (preservedModal) preservedModal.remove();

        mount.innerHTML = state.phase === 'signedIn'
            ? signedInMarkup()
            : state.phase === 'subscription'
                ? subscriptionSignupMarkup()
                : signedOutMarkup();
        bindCommonActions();
        if (state.phase === 'subscription') bindSubscriptionActions();
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
        if (modal.type === 'admin-command-run') {
            return [
                modal.type,
                modal.runId || modal.result?.id || '',
                modal.status || '',
                modal.result?.status || '',
                modal.result?.exit_code ?? modal.result?.exitCode ?? '',
                modal.result?.updated_at || modal.result?.updatedAt || '',
                String(modal.result?.output || '').length,
                String(modal.result?.error || '').length,
                modal.error || '',
            ].map((part) => String(part)).join(':');
        }

        return [
            modal.type || '',
            modal.mode || '',
            modal.item?.id || '',
            modal.parentTask?.id || '',
            modal.workspace?.id || '',
            modal.log?.id || '',
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
                                <h1>${forgot ? 'Reset password' : register ? 'We are currently onboarding beta users.' : 'Login'}</h1>
                                ${register ? "<p class=\"hb-register-intro\">Sign up for early access and we'll let you know as soon as we are ready to onboard you!</p>" : ''}
                            </div>
                        </div>
                        ${errorMarkup(state.error)}
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
                ${register ? labelInput('Name', 'name', 'text', '', 'autocomplete="name"') : ''}
                ${labelInput('Email', 'email', 'email', '', 'required autocomplete="email"')}
                ${register && state.selectedPlan ? `<input type="hidden" name="plan" value="${escapeAttr(state.selectedPlan)}">` : ''}
                ${register ? `
                    ${labelInput('Password', 'password', 'password', '', 'required autocomplete="new-password" minlength="12"')}
                    ${labelInput('Confirm password', 'password_confirmation', 'password', '', 'required autocomplete="new-password" minlength="12"')}
                ` : `
                    ${labelInput('Password', 'password', 'password', '', 'required autocomplete="current-password" minlength="1"')}
                    <label class="hb-checkbox-row"><input type="checkbox" name="remember" ${state.remember ? 'checked' : ''}> Remember me</label>
                `}
                <button class="hb-button" type="submit" ${state.busy ? 'disabled' : ''}>${state.busy ? (register ? 'Signing up…' : 'Signing in…') : (register ? 'Sign up for early access' : 'Sign in')}</button>
                <div class="hb-link-row">
                    <button class="hb-button-ghost" type="button" data-auth-mode="${register ? 'login' : 'register'}">${register ? 'Already have an account? Sign in' : 'Join the waitlist'}</button>
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

    function subscriptionSignupMarkup() {
        if (state.phase === 'loading') {
            return `<div class="hb-loading-screen"><div class="hb-spinner"></div><p>Loading subscription setup…</p></div>`;
        }
        const checkoutStatus = String(state.subscriptionCheckoutStatus || '').toLowerCase();
        const confirmed = checkoutStatus === 'success';
        const canceled = checkoutStatus === 'cancel';
        const selectedPlan = subscriptionPlans[state.selectedPlan] ? state.selectedPlan : 'premium';
        const subscription = state.subscriptionSummary || {};
        const status = String(subscription.status || state.user?.subscription_status || state.user?.subscriptionStatus || '').toLowerCase();
        const liveConfirmed = ['active', 'trialing'].includes(status);
        return `
            <div class="hb-app">
                <main class="hb-subscribe-wrap">
                    <section class="hb-subscribe-shell">
                        <div class="hb-subscribe-hero hb-card">
                            <div class="hb-subscribe-brand">
                                <img src="${escapeAttr(logoUrl)}" alt="">
                                <span>HeyBean</span>
                            </div>
                            <div class="hb-subscribe-kicker">7-day free trial</div>
                            <h1>${confirmed ? 'Your subscription is ready' : 'Choose your Bean subscription'}</h1>
                            <p>${confirmed ? subscriptionConfirmationCopy(liveConfirmed, selectedPlan) : 'Your account is created. Pick the plan that fits how much of your calendar, tasks, reminders, and daily context you want Bean to handle.'}</p>
                            ${subscriptionProgressMarkup(confirmed ? 4 : 2)}
                            ${errorMarkup(state.error)}
                            ${canceled ? '<div class="hb-error"><strong>Checkout was canceled</strong><span>No charge was made. Choose a plan when you are ready to continue.</span></div>' : ''}
                            ${confirmed ? subscriptionConfirmationMarkup(selectedPlan, subscription, liveConfirmed) : subscriptionPlanSelectionMarkup(selectedPlan)}
                        </div>
                    </section>
                </main>
            </div>`;
    }

    function subscriptionProgressMarkup(activeStep) {
        const steps = [
            ['1', 'Account'],
            ['2', 'Plan'],
            ['3', 'Payment'],
            ['4', 'Dashboard'],
        ];
        return `
            <div class="hb-subscribe-steps" aria-label="Signup progress">
                ${steps.map(([number, label], index) => {
                    const step = index + 1;
                    return `
                        <div class="hb-subscribe-step ${step <= activeStep ? 'hb-subscribe-step-active' : ''}">
                            <span>${number}</span>
                            <strong>${label}</strong>
                        </div>`;
                }).join('')}
            </div>`;
    }

    function subscriptionPlanSelectionMarkup(selectedPlan) {
        return `
            <div class="hb-subscribe-grid">
                ${Object.entries(subscriptionPlans).map(([key, plan]) => subscriptionPlanCardMarkup(key, plan, key === selectedPlan)).join('')}
            </div>
            <div class="hb-subscribe-footer">
                <p>Payment is handled securely through Stripe. Billing starts on day 8 and renews monthly until canceled.</p>
                <button class="hb-button-ghost" type="button" data-subscribe-logout>Use a different account</button>
            </div>`;
    }

    function subscriptionPlanCardMarkup(key, plan, selected) {
        const busy = state.busy && state.selectedPlan === key;
        return `
            <article class="hb-subscribe-plan ${plan.popular ? 'hb-subscribe-plan-popular' : ''} ${selected ? 'hb-subscribe-plan-selected' : ''}">
                ${plan.popular ? '<span class="hb-subscribe-badge">Most popular</span>' : ''}
                <div class="hb-subscribe-plan-head">
                    <div>
                        <h2>${escapeHtml(plan.label)}</h2>
                        <p>${escapeHtml(plan.bestFor)}</p>
                    </div>
                    <div class="hb-subscribe-price"><strong>${escapeHtml(plan.price)}</strong><span>/mo</span></div>
                </div>
                <div class="hb-subscribe-trial">7-day free trial, then billed monthly</div>
                <ul>
                    ${normalizeList(plan.features).map((feature) => `<li>${icons.checkCircle}<span>${escapeHtml(feature)}</span></li>`).join('')}
                </ul>
                <button class="${plan.popular ? 'hb-button' : 'hb-button-secondary'}" type="button" data-subscribe-plan="${escapeAttr(key)}" ${state.busy ? 'disabled' : ''}>
                    ${busy ? '<span class="hb-spinner"></span> Opening payment…' : `Start ${escapeHtml(plan.label)} trial`}
                </button>
            </article>`;
    }

    function subscriptionConfirmationCopy(liveConfirmed, selectedPlan) {
        const plan = subscriptionPlans[selectedPlan] || subscriptionPlans.premium;
        if (liveConfirmed) {
            return `${plan.label} is active. Bean is ready to open your dashboard.`;
        }
        return `Stripe sent you back to HeyBean. ${plan.label} setup is recorded, and Bean will update the live subscription status as soon as Stripe confirms it.`;
    }

    function subscriptionConfirmationMarkup(selectedPlan, subscription, liveConfirmed) {
        const plan = subscriptionPlans[selectedPlan] || subscriptionPlans.premium;
        const trialEndsAt = subscription.trial_ends_at || subscription.trialEndsAt || state.user?.subscription_trial_ends_at || state.user?.subscriptionTrialEndsAt || '';
        const currentPeriodEnd = subscription.current_period_end || subscription.currentPeriodEnd || '';
        return `
            <div class="hb-subscribe-confirmation">
                <div class="hb-success">
                    <strong>${liveConfirmed ? `${escapeHtml(plan.label)} confirmed` : `${escapeHtml(plan.label)} setup submitted`}</strong>
                    <span>${escapeHtml(subscriptionBillingSummary(plan, trialEndsAt, currentPeriodEnd))}</span>
                </div>
                <div class="hb-subscribe-summary-grid">
                    <div><span>Plan</span><strong>${escapeHtml(plan.label)}</strong></div>
                    <div><span>Monthly price</span><strong>${escapeHtml(plan.price)}/mo</strong></div>
                    <div><span>Trial</span><strong>7 days</strong></div>
                    <div><span>Billing cycle</span><strong>Monthly</strong></div>
                </div>
                <div class="hb-subscribe-actions">
                    <button class="hb-button" type="button" data-subscribe-dashboard>Go to dashboard</button>
                    <button class="hb-button-secondary" type="button" data-subscribe-refresh ${state.busy ? 'disabled' : ''}>${state.busy ? 'Refreshing…' : 'Refresh subscription status'}</button>
                </div>
            </div>`;
    }

    function subscriptionBillingSummary(plan, trialEndsAt, currentPeriodEnd) {
        if (trialEndsAt) return `${plan.label} starts with a free trial through ${formatDateTime(trialEndsAt)}. After that, billing continues monthly until canceled.`;
        if (currentPeriodEnd) return `${plan.label} is billed monthly. Your current billing cycle renews around ${formatDateTime(currentPeriodEnd)}.`;
        return `${plan.label} starts with a 7-day free trial. Billing begins on day 8 and continues monthly until canceled.`;
    }

    function signedInMarkup() {
        const criticalTasks = criticalTasksForToday();
        const criticalReminders = criticalRemindersForToday();
        const criticalEvents = criticalEventsForToday();
        const showAdd = ['today', 'tasks', 'reminders'].includes(state.selected);
        const now = new Date();
        return `
            <div class="hb-app">
                ${betaBannerMarkup()}
                <header class="hb-topbar">
                    ${topBeanControlsMarkup()}
                    <span class="hb-spacer"></span>
                    <div class="hb-topbar-date-line">
                        <time class="hb-topbar-current-time" data-current-time datetime="${escapeAttr(now.toISOString())}">${escapeHtml(formatTopbarTime(now))}</time>
                        <button class="hb-header-pill" data-today type="button"><span>${escapeHtml(topbarTodayLabel(now))}</span></button>
                        <button class="hb-header-pill hb-month-pill" data-calendar-month type="button"><span>${escapeHtml(monthLabel(now))}</span></button>
                    </div>
                    ${state.selected === 'today' && state.showMonth ? `<div class="hb-topbar-month-cluster">${monthSwitcherMarkup(parseLocalDate(state.selectedDay))}</div>` : ''}
                    ${topNavMarkup()}
                    ${showAdd ? topCreateMenuMarkup() : ''}
                    ${criticalMenuMarkup(criticalTasks, criticalReminders, criticalEvents)}
                    ${topProfileMenuMarkup()}
                </header>
                <main class="hb-main ${state.selected === 'bean' ? 'hb-main-chat' : ''} ${state.selected === 'today' ? 'hb-main-today' : ''} ${['tasks', 'reminders'].includes(state.selected) ? 'hb-main-board' : ''} ${state.selected === 'admin' ? 'hb-main-admin' : ''}">
                    ${state.selected === 'bean' ? chatMarkup() : appPanelMarkup()}
                </main>
                ${state.selected === 'bean' ? '' : approvalSheetMarkup()}
                ${bottomMenuMarkup()}
                ${state.chatExpanded && state.selected !== 'bean' ? desktopChatMarkup({ expanded: true }) : ''}
                ${onboardingTourMarkup()}
            </div>`;
    }

    function betaBannerMarkup() {
        if (!userIsEarlyAccess()) return '';

        return `<button class="hb-beta-banner" type="button" data-open-issue-report>You are in our Beta testing phase. If you have any issues, please report them here.</button>`;
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
        const archivedIssueReports = normalizeList(usage.archived_issue_reports || usage.archivedIssueReports);
        const recentLogs = normalizeList(usage.recent_logs || usage.recentLogs);
        const byModel = normalizeList(usage.by_model || usage.byModel);
        const byRoute = normalizeList(usage.by_route_tier || usage.byRouteTier);
        const topUsers = normalizeList(usage.top_users || usage.topUsers);
        const topWorkspaces = normalizeList(usage.top_workspaces || usage.topWorkspaces);
        const settings = usage.settings || {};
        const killSwitches = settings.kill_switches || settings.killSwitches || {};
        const userGrowth = normalizeList(usage.user_growth || usage.userGrowth);
        return `
            <section class="hb-card hb-card-pad hb-admin-panel">
                <div class="hb-section-action-row">
                    ${sectionTitle(icons.activity, 'Admin monitor', 'AI cost, usage limits, and user activity')}
                    <button class="hb-button-secondary" type="button" data-refresh-admin ${state.adminUsageLoading ? 'disabled' : ''}>${state.adminUsageLoading ? 'Refreshing...' : 'Refresh'}</button>
                </div>
                ${errorMarkup(state.error)}
                ${state.adminUsageLoading && !state.adminUsage ? '<div class="hb-empty hb-surface-soft">Loading AI usage metrics...</div>' : ''}
                ${adminUserGrowthChartMarkup(userGrowth)}
                <div class="hb-admin-metrics">
                    ${adminMetricMarkup('Users', totals.users, 'Total accounts')}
                    ${adminMetricMarkup('Workspaces', totals.workspaces, 'Total spaces')}
                    ${adminMetricMarkup('Actions today', totals.ai_actions_today, `${formatTokens(totals.tokens_today)} tokens`)}
                    ${adminMetricMarkup('Month cost', formatCurrency(totals.cost_month), `${formatTokens(totals.tokens_month)} tokens`)}
                    ${adminMetricMarkup('Today cost', formatCurrency(totals.cost_today), `${totals.ai_actions_month || 0} actions this month`)}
                    ${adminMetricMarkup('Voice today', formatTokens(totals.audio_tokens_today || totals.audioTokensToday), 'Audio tokens')}
                    ${adminMetricMarkup('Tools today', totals.tool_calls_today || totals.toolCallsToday || 0, 'External/internal calls')}
                    ${adminMetricMarkup('Open alerts', totals.open_alerts, 'Warnings and hard caps')}
                    ${adminMetricMarkup('Issue reports', totals.open_issue_reports, 'Open beta feedback')}
                </div>
                ${adminHermesMaintenanceMarkup()}
                ${adminSettingsMarkup(settings)}
                ${adminPlanLimitsMarkup(state.adminPlanLimits)}
                <div class="hb-admin-grid">
                    ${adminIssueReportsBlockMarkup(issueReports, archivedIssueReports)}
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
                        <div class="hb-admin-log-head"><span>When</span><span>Use case</span><span>Request</span><span>User</span><span>Workspace</span><span>Model</span><span>Tokens</span><span>Audio</span><span>Tools</span><span>Cost</span><span>Status</span></div>
                        ${recentLogs.map(adminLogRowMarkup).join('') || '<div class="hb-empty">No AI usage logs yet.</div>'}
                    </div>
                </div>
            </section>`;
    }

    function adminUserGrowthChartMarkup(points) {
        const selectedRange = state.adminUserGrowthRange || 'last_30_days';
        const values = normalizeList(points).map((point) => ({
            day: point.day || point.date || '',
            newUsers: Number(point.new_users ?? point.newUsers ?? 0),
            totalUsers: Number(point.total_users ?? point.totalUsers ?? 0),
        }));
        const latest = values[values.length - 1] || { totalUsers: 0, newUsers: 0, day: '' };
        const totalNew = values.reduce((sum, point) => sum + point.newUsers, 0);
        const width = 760;
        const height = 260;
        const padLeft = 58;
        const padRight = 28;
        const padTop = 28;
        const padBottom = 38;
        const max = niceChartMax(Math.max(1, ...values.map((point) => point.totalUsers)));
        const yTicks = chartYTicks(max, max <= 5 ? max : 5);
        const xFor = (index) => values.length <= 1 ? padLeft : padLeft + (index / (values.length - 1)) * (width - padLeft - padRight);
        const yFor = (value) => height - padBottom - (value / max) * (height - padTop - padBottom);
        const path = values.map((point, index) => `${index === 0 ? 'M' : 'L'} ${xFor(index).toFixed(1)} ${yFor(point.totalUsers).toFixed(1)}`).join(' ');
        const area = path ? `${path} L ${xFor(values.length - 1).toFixed(1)} ${height - padBottom} L ${padLeft} ${height - padBottom} Z` : '';
        const startLabel = values[0]?.day ? monthDayLabel(values[0].day) : '';
        const endLabel = latest.day ? monthDayLabel(latest.day) : '';
        const rangeLabel = userGrowthRangeLabel(selectedRange);

        return `
            <div class="hb-admin-growth-card">
                <div class="hb-admin-growth-header">
                    <div>
                        <strong>User growth</strong>
                        <small>${escapeHtml(rangeLabel)}, cumulative accounts</small>
                    </div>
                    <div class="hb-admin-growth-range" role="group" aria-label="User growth range">
                        ${userGrowthRangeButtonMarkup('today', 'Today')}
                        ${userGrowthRangeButtonMarkup('last_7_days', '7 days')}
                        ${userGrowthRangeButtonMarkup('last_30_days', '30 days')}
                        ${userGrowthRangeButtonMarkup('all_time', 'All time')}
                    </div>
                    <div class="hb-admin-growth-stats">
                        <span><strong>${escapeHtml(latest.totalUsers)}</strong><small>Total users</small></span>
                        <span><strong>+${escapeHtml(totalNew)}</strong><small>${escapeHtml(rangeLabel)}</small></span>
                    </div>
                </div>
                <svg class="hb-admin-growth-chart" viewBox="0 0 ${width} ${height}" role="img" aria-label="User growth line chart">
                    <defs>
                        <linearGradient id="hb-admin-growth-fill" x1="0" y1="0" x2="0" y2="1">
                            <stop offset="0%" stop-color="var(--hb-accent)" stop-opacity=".24"></stop>
                            <stop offset="100%" stop-color="var(--hb-accent)" stop-opacity="0"></stop>
                        </linearGradient>
                    </defs>
                    ${yTicks.map((tick) => `
                        <line x1="${padLeft}" y1="${yFor(tick).toFixed(1)}" x2="${width - padRight}" y2="${yFor(tick).toFixed(1)}" class="${tick === 0 ? 'hb-admin-growth-axis' : 'hb-admin-growth-grid'}"></line>
                        <text x="${padLeft - 10}" y="${(yFor(tick) + 4).toFixed(1)}" text-anchor="end" class="hb-admin-growth-label">${escapeHtml(formatCompactNumber(tick))}</text>
                    `).join('')}
                    ${area ? `<path d="${escapeAttr(area)}" class="hb-admin-growth-area"></path>` : ''}
                    ${path ? `<path d="${escapeAttr(path)}" class="hb-admin-growth-line"></path>` : ''}
                    ${values.map((point, index) => `<circle cx="${xFor(index).toFixed(1)}" cy="${yFor(point.totalUsers).toFixed(1)}" r="${index === values.length - 1 ? 4.8 : 2.8}" class="hb-admin-growth-dot"><title>${escapeHtml(`${monthDayLabel(point.day)}: ${point.totalUsers} users, +${point.newUsers}`)}</title></circle>`).join('')}
                    <text x="${padLeft}" y="${height - 8}" class="hb-admin-growth-label">${escapeHtml(startLabel)}</text>
                    <text x="${width - padRight}" y="${height - 8}" text-anchor="end" class="hb-admin-growth-label">${escapeHtml(endLabel)}</text>
                </svg>
            </div>`;
    }

    function userGrowthRangeButtonMarkup(range, label) {
        const active = (state.adminUserGrowthRange || 'last_30_days') === range;
        return `<button class="hb-admin-growth-range-button" type="button" data-user-growth-range="${escapeAttr(range)}" aria-pressed="${active}">${escapeHtml(label)}</button>`;
    }

    function userGrowthRangeLabel(range) {
        return {
            today: 'Today',
            last_7_days: 'Last 7 days',
            last_30_days: 'Last 30 days',
            all_time: 'All time',
        }[range] || 'Last 30 days';
    }

    function niceChartMax(value) {
        const numeric = Math.max(1, Number(value || 1));
        const exponent = Math.floor(Math.log10(numeric));
        const base = 10 ** exponent;
        const fraction = numeric / base;
        const niceFraction = fraction <= 1 ? 1 : fraction <= 2 ? 2 : fraction <= 5 ? 5 : 10;
        return niceFraction * base;
    }

    function chartYTicks(max, segments = 4) {
        const count = Math.max(1, Number(segments || 1));
        return Array.from({ length: count + 1 }, (_, index) => Math.round((max / count) * index))
            .filter((tick, index, ticks) => index === 0 || tick !== ticks[index - 1]);
    }

    function adminSettingsMarkup(settings) {
        const models = settings.models || {};
        const usage = settings.usage_limits || settings.usageLimits || {};
        const killSwitches = settings.kill_switches || settings.killSwitches || {};
        const registry = state.adminModelRegistry || {};
        return `
            <form class="hb-admin-settings" data-admin-settings-form>
                <div class="hb-section-action-row">
                    <div>
                        <strong>Runtime settings</strong>
                        <small>Model routing and daily usage cost limits</small>
                    </div>
                    <button class="hb-button-secondary" type="submit" ${state.adminUsageLoading ? 'disabled' : ''}>Save settings</button>
                </div>
                ${registry.openai_available === false || registry.openaiAvailable === false ? `<div class="hb-admin-model-note">Using curated model options. ${escapeHtml(registry.error || '')}</div>` : ''}
                <div class="hb-admin-settings-grid">
                    ${adminModelSelectMarkup('main_model', models.main_model || models.mainModel)}
                    ${adminModelSelectMarkup('quick_voice_model', models.quick_voice_model || models.quickVoiceModel)}
                    ${adminModelSelectMarkup('realtime_model', models.realtime_model || models.realtimeModel)}
                    ${adminModelSelectMarkup('external_lookup_model', models.external_lookup_model || models.externalLookupModel)}
                </div>
                <div class="hb-admin-settings-grid hb-admin-kill-grid">
                    ${adminSwitchMarkup('bean_chat_enabled', 'Bean chat enabled', 'Pause all Bean text/background requests immediately.', settingValue(killSwitches.bean_chat_enabled || killSwitches.beanChatEnabled) !== false)}
                    ${adminSwitchMarkup('bean_voice_enabled', 'Bean voice enabled', 'Pause realtime voice, quick voice replies, and TTS immediately.', settingValue(killSwitches.bean_voice_enabled || killSwitches.beanVoiceEnabled) !== false)}
                </div>
                <label class="hb-admin-apply-row">
                    <input type="checkbox" name="apply_main_model_to_profiles">
                    <span>Apply main model to existing workspace Bean profiles</span>
                </label>
            </form>`;
    }

    function adminPlanLimitsMarkup(planLimits) {
        if (!planLimits) {
            return '<div class="hb-admin-settings"><strong>Plan limits</strong><div class="hb-empty">Loading plan limits...</div></div>';
        }
        const plans = planLimits.plans || {};
        const enterpriseCustomers = normalizeList(planLimits.enterprise_customers || planLimits.enterpriseCustomers);
        return `
            <section class="hb-admin-settings" data-admin-plan-limits-panel>
                <form data-admin-plan-limits-form>
                    <div class="hb-section-action-row">
                        <div>
                            <strong>Plan limits</strong>
                            <small>Feature gates, workspace limits, history, and daily Bean budgets by tier</small>
                        </div>
                        <button class="hb-button-secondary" type="submit" ${state.adminUsageLoading ? 'disabled' : ''}>Save plan limits</button>
                    </div>
                    <div class="hb-admin-settings-grid">
                        ${['base', 'premium', 'pro'].map((plan) => adminPlanLimitCardMarkup(plan, plans[plan] || {})).join('')}
                    </div>
                </form>
                <div class="hb-section-action-row hb-admin-enterprise-heading">
                    <div>
                        <strong>Enterprise customers</strong>
                        <small>Per-customer limits and agreed billing terms</small>
                    </div>
                </div>
                <div class="hb-admin-settings-grid">
                    ${enterpriseCustomers.map(adminEnterpriseLimitFormMarkup).join('')}
                    ${adminEnterpriseLimitFormMarkup({})}
                </div>
            </section>`;
    }

    function adminPlanLimitCardMarkup(plan, payload) {
        const limits = payload.value || payload.default || {};
        const label = { base: 'Base', premium: 'Premium', pro: 'Pro' }[plan] || plan;
        return `
            <div class="hb-surface-soft hb-card-pad" data-plan-limit-card="${escapeAttr(plan)}">
                <strong>${escapeHtml(label)}</strong>
                <small>${payload.is_overridden ? 'Custom admin limits' : 'Using defaults'}</small>
                ${adminLimitInputsMarkup(limits)}
            </div>`;
    }

    function adminEnterpriseLimitFormMarkup(customer) {
        const id = customer.id || '';
        const user = customer.user || {};
        const limits = customer.limits || {};
        return `
            <form class="hb-surface-soft hb-card-pad" data-enterprise-limit-form="${escapeAttr(id)}">
                <div class="hb-section-action-row">
                    <div>
                        <strong>${escapeHtml(id ? (user.name || user.email || `User #${customer.user_id || customer.userId}`) : 'Add enterprise customer')}</strong>
                        <small>${escapeHtml(id ? (user.email || `User #${customer.user_id || customer.userId}`) : 'Enter an existing user id')}</small>
                    </div>
                    ${id ? `<button class="hb-admin-mini-action" type="button" data-enterprise-limit-delete="${escapeAttr(id)}">Remove</button>` : ''}
                </div>
                <label><span>User id</span><input class="hb-input" type="number" min="1" name="user_id" value="${escapeAttr(customer.user_id || customer.userId || '')}" ${id ? 'readonly' : ''}></label>
                <label><span>Billing type</span><select class="hb-input" name="billing_type">
                    <option value="monthly" ${(customer.billing_type || customer.billingType || 'monthly') === 'monthly' ? 'selected' : ''}>Set monthly rate</option>
                    <option value="usage" ${(customer.billing_type || customer.billingType) === 'usage' ? 'selected' : ''}>Dynamic usage rate</option>
                </select></label>
                <label><span>Monthly rate USD</span><input class="hb-input" type="number" min="0" step="0.01" name="monthly_rate_usd" value="${escapeAttr(customer.monthly_rate_usd ?? customer.monthlyRateUsd ?? '')}"></label>
                <label><span>Usage rate USD</span><input class="hb-input" type="number" min="0" step="0.000001" name="usage_rate_usd" value="${escapeAttr(customer.usage_rate_usd ?? customer.usageRateUsd ?? '')}"></label>
                ${adminLimitInputsMarkup(limits)}
                <label><span>Notes</span><textarea class="hb-input" name="notes" rows="3">${escapeHtml(customer.notes || '')}</textarea></label>
                <button class="hb-button-secondary" type="submit" ${state.adminUsageLoading ? 'disabled' : ''}>${id ? 'Save enterprise customer' : 'Add enterprise customer'}</button>
            </form>`;
    }

    function adminLimitInputsMarkup(limits = {}) {
        return `
            <label><span>Workspace limit</span><input class="hb-input" type="number" min="0" name="workspace_limit" placeholder="Unlimited" value="${escapeAttr(limitInputValue(limits.workspace_limit ?? limits.workspaceLimit))}"></label>
            <label><span>Calendar limit</span><input class="hb-input" type="number" min="0" name="calendar_connection_limit" placeholder="Unlimited" value="${escapeAttr(limitInputValue(limits.calendar_connection_limit ?? limits.calendarConnectionLimit))}"></label>
            <label><span>Connected account limit</span><input class="hb-input" type="number" min="0" name="connected_account_limit" placeholder="Unlimited" value="${escapeAttr(limitInputValue(limits.connected_account_limit ?? limits.connectedAccountLimit))}"></label>
            <label><span>History days</span><input class="hb-input" type="number" min="0" name="history_days" placeholder="Unlimited" value="${escapeAttr(limitInputValue(limits.history_days ?? limits.historyDays))}"></label>
            <label><span>Daily Bean cost</span><input class="hb-input" type="number" min="0" step="0.01" name="daily_cost_limit" placeholder="Unlimited" value="${escapeAttr(limitInputValue(limits.daily_cost_limit ?? limits.dailyCostLimit))}"></label>
            <label><span>Daily external cost</span><input class="hb-input" type="number" min="0" step="0.01" name="daily_external_cost_limit" placeholder="Unlimited" value="${escapeAttr(limitInputValue(limits.daily_external_cost_limit ?? limits.dailyExternalCostLimit))}"></label>
            <div class="hb-admin-kill-grid">
                ${adminSwitchMarkup('recurring_tasks_enabled', 'Recurring tasks', 'Allow recurring tasks for this tier/customer.', Boolean(limits.recurring_tasks_enabled ?? limits.recurringTasksEnabled))}
                ${adminSwitchMarkup('recurring_reminders_enabled', 'Recurring reminders', 'Allow recurring reminders for this tier/customer.', Boolean(limits.recurring_reminders_enabled ?? limits.recurringRemindersEnabled))}
                ${adminSwitchMarkup('recurring_calendar_enabled', 'Recurring calendar events', 'Allow recurring calendar event series.', Boolean(limits.recurring_calendar_enabled ?? limits.recurringCalendarEnabled))}
                ${adminSwitchMarkup('email_reminders_enabled', 'Email reminders', 'Allow reminder delivery by email.', Boolean(limits.email_reminders_enabled ?? limits.emailRemindersEnabled))}
                ${adminSwitchMarkup('priority_background_work', 'Priority background work', 'Prefer this tier/customer for priority background handling.', Boolean(limits.priority_background_work ?? limits.priorityBackgroundWork))}
            </div>`;
    }

    function limitInputValue(value) {
        return value === null || value === undefined ? '' : value;
    }

    function adminSwitchMarkup(name, label, help, enabled) {
        return `
            <label class="hb-admin-switch">
                <input type="checkbox" name="${escapeAttr(name)}" ${enabled ? 'checked' : ''}>
                <span>
                    <strong>${escapeHtml(label)}</strong>
                    <small>${escapeHtml(help)}</small>
                </span>
            </label>`;
    }

    function adminHermesMaintenanceMarkup() {
        const status = state.adminHermesStatus || {};
        const version = status.version || 'Unknown version';
        const updateAvailable = status.update_available === true || status.updateAvailable === true;
        const configured = status.configured !== false;
        const checkedAt = status.checked_at || status.checkedAt;
        const error = status.error || '';

        return `
            <div class="hb-admin-settings hb-admin-hermes-card">
                <div class="hb-section-action-row">
                    <div>
                        <strong>Hermes agent runtime</strong>
                        <small>${escapeHtml(configured ? 'Server-hosted runtime harness for all Bean agents' : 'Hermes CLI could not be reached')}</small>
                    </div>
                    <button class="hb-button-secondary" type="button" data-update-hermes ${state.adminHermesUpdating ? 'disabled' : ''}>${state.adminHermesUpdating ? 'Updating...' : 'Update Hermes'}</button>
                </div>
                <div class="hb-admin-hermes-status">
                    <div>
                        <span>Current version</span>
                        <strong>${escapeHtml(version)}</strong>
                        <small>${checkedAt ? `Checked ${escapeHtml(formatDateTime(checkedAt))}` : 'Not checked yet'}</small>
                    </div>
                    <mark class="hb-admin-status ${updateAvailable ? 'hb-admin-status-warning' : ''}">${escapeHtml(updateAvailable ? 'Update available' : configured ? 'Current' : 'Unavailable')}</mark>
                </div>
                ${error ? `<div class="hb-admin-model-note">${escapeHtml(error)}</div>` : ''}
                <small class="hb-admin-hermes-path">${escapeHtml(status.cli_path || status.cliPath || 'hermes')} · ${escapeHtml(status.users_home || status.usersHome || '')}</small>
            </div>`;
    }

    function adminModelSelectMarkup(name, setting) {
        const registry = state.adminModelRegistry || {};
        const group = registry.groups?.[name] || {};
        const models = normalizeList(group.models);
        const value = String(settingValue(setting) || '').trim();
        const options = models.some((model) => model.id === value)
            ? models
            : [{ id: value, label: value, source: 'current', available: true, recommended: false }, ...models].filter((model) => model.id);

        return `
            <label class="hb-admin-model-field">
                <span>${escapeHtml(group.label || modelSettingLabel(name))}</span>
                <select class="hb-input" name="${escapeAttr(name)}" data-admin-model-select="${escapeAttr(name)}">
                    ${options.map((model) => {
                        const id = String(model.id || '');
                        const source = model.source === 'openai' ? 'OpenAI' : model.source === 'current' ? 'Current' : 'Curated';
                        const suffix = model.recommended ? ' recommended' : '';
                        return `<option value="${escapeAttr(id)}" ${id === value ? 'selected' : ''}>${escapeHtml(model.label || id)} · ${escapeHtml(source)}${suffix}</option>`;
                    }).join('')}
                </select>
                <small>${escapeHtml(group.description || '')}</small>
            </label>`;
    }

    function modelSettingLabel(name) {
        return {
            main_model: 'Main Bean reasoning/chat',
            quick_voice_model: 'Quick voice response',
            realtime_model: 'Realtime voice',
            external_lookup_model: 'External lookup',
        }[name] || name;
    }

    function settingValue(setting) {
        return setting && typeof setting === 'object' && Object.prototype.hasOwnProperty.call(setting, 'value')
            ? setting.value
            : '';
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

    function adminIssueReportsBlockMarkup(openReports, archivedReports) {
        const archivedOpen = Boolean(state.adminArchivedIssuesOpen);
        return `
            <div class="hb-surface-soft hb-card-pad hb-admin-list-block">
                <div class="hb-admin-list-heading">
                    <strong>Issue reports</strong>
                    ${archivedReports.length ? `<button class="hb-admin-inline-link" type="button" data-toggle-archived-issues>${archivedOpen ? 'Hide archived' : `Archived issues (${archivedReports.length})`}</button>` : ''}
                </div>
                <div class="hb-admin-list">
                    ${openReports.length ? openReports.map(adminIssueReportRowMarkup).join('') : '<div class="hb-empty">No open issue reports.</div>'}
                </div>
                ${archivedOpen ? `
                    <div class="hb-admin-archived-issues">
                        <strong>Archived issues</strong>
                        <div class="hb-admin-list">
                            ${archivedReports.length ? archivedReports.map(adminIssueReportRowMarkup).join('') : '<div class="hb-empty">No archived issues.</div>'}
                        </div>
                    </div>` : ''}
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
        const tier = row.subscription_tier || row.subscriptionTier || 'base';
        const displayTier = tier === 'free' ? 'base' : tier;
        return `
            <div class="hb-admin-row">
                <div><strong>${escapeHtml(row.name || row.email || 'User')}</strong><small>${escapeHtml(`${row.email || ''} · ${displayTier}`)}</small></div>
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
        const status = String(report.status || 'open').toLowerCase();
        const id = report.id || report.issue_report_id || report.issueReportId;
        const closed = status === 'closed';
        return `
            <div class="hb-admin-row hb-admin-issue-row">
                <div>
                    <strong>${escapeHtml(report.message || 'Issue report')}</strong>
                    <small>${escapeHtml(user.email || user.name || 'Unknown user')} · ${escapeHtml(workspace.name || 'No workspace')} · ${escapeHtml(formatDateTime(report.created_at || report.createdAt))}</small>
                    ${report.page_url || report.pageUrl ? `<a href="${escapeAttr(report.page_url || report.pageUrl)}" target="_blank" rel="noreferrer">Reported page</a>` : ''}
                    ${screenshots.length ? `<div class="hb-admin-issue-shots">${screenshots.map((shot, index) => `<a href="${escapeAttr(shot.url || '')}" target="_blank" rel="noreferrer">Screenshot ${index + 1}</a>`).join('')}</div>` : ''}
                </div>
                <span class="hb-admin-issue-controls">
                    <mark class="hb-admin-status">${escapeHtml(status)}</mark>
                    ${id && !closed ? `<button class="hb-admin-mini-action" type="button" data-issue-status="${escapeAttr(id)}" data-status="closed">Close</button>` : ''}
                    ${id && closed ? `<button class="hb-admin-mini-action" type="button" data-issue-status="${escapeAttr(id)}" data-status="open">Reopen</button>` : ''}
                </span>
            </div>`;
    }

    function adminLogRowMarkup(log) {
        const user = log.user || {};
        const workspace = log.workspace || {};
        const useCase = log.use_case || log.useCase || 'General Bean request';
        const requestPreview = log.request_preview || log.requestPreview || '';
        const actionSummary = log.action_summary || log.actionSummary || normalizeList(log.action_types || log.actionTypes).join(', ');
        const id = log.id || '';
        return `
            <button class="hb-admin-log-row" type="button" data-admin-log-id="${escapeAttr(id)}" aria-label="View full AI usage log input">
                <span>${escapeHtml(formatDateTime(log.created_at || log.createdAt))}</span>
                <span><mark class="hb-admin-use-case">${escapeHtml(useCase)}</mark></span>
                <span>${escapeHtml(requestPreview || 'No request captured')}<small>${escapeHtml(actionSummary || 'No tools/actions')}</small></span>
                <span>${escapeHtml(user.name || user.email || `#${log.user_id || log.userId || ''}`)}</span>
                <span>${escapeHtml(workspace.name || (log.workspace_id || log.workspaceId ? `#${log.workspace_id || log.workspaceId}` : 'None'))}</span>
                <span>${escapeHtml(log.model || 'unknown')}<small>${escapeHtml(log.route_tier || log.routeTier || '')}</small></span>
                <span>${escapeHtml(formatTokens(log.total_tokens || log.totalTokens))}</span>
                <span>${escapeHtml(formatTokens((log.audio_input_tokens || log.audioInputTokens || 0) + (log.audio_output_tokens || log.audioOutputTokens || 0)))}</span>
                <span>${escapeHtml(log.tool_call_count || log.toolCallCount || 0)}</span>
                <span>${escapeHtml(formatCurrency(log.estimated_cost_usd || log.estimatedCostUsd))}</span>
                <span><mark class="hb-admin-status">${escapeHtml(log.status || 'logged')}</mark></span>
            </button>`;
    }

    function beanWorkStatusMarkup(options = {}) {
        const active = beanWorkStatusActive();
        if (!active && options.mobile) return '';
        const items = beanWorkDisplayItems();
        const completedCount = items.filter((item) => beanWorkItemDone(item)).length;
        const label = active ? beanWorkStatusLabel(items) : state.chatRunState || 'Ready';
        const expanded = active && items.length > 0;
        return `
            <section class="hb-bean-work-status ${active ? 'hb-bean-work-status-active' : ''} ${options.mobile ? 'hb-bean-work-status-mobile' : ''}" aria-live="polite">
                <div class="hb-bean-work-status-head">
                    <span class="hb-bean-work-status-dot" aria-hidden="true"></span>
                    <span class="hb-bean-work-status-title">${escapeHtml(label)}</span>
                    ${items.length ? `<span class="hb-bean-work-status-count">${escapeHtml(`${completedCount}/${items.length}`)}</span>` : ''}
                </div>
                ${expanded ? beanWorkListMarkup(items) : ''}
            </section>`;
    }

    function beanWorkListMarkup(items, className = 'hb-bean-work-list') {
        return `
            <ul class="${escapeAttr(className)}" aria-label="Bean work queue" ${items.length ? '' : 'aria-hidden="true"'}>
                ${items.map(beanWorkItemMarkup).join('')}
            </ul>`;
    }

    function beanWorkItemMarkup(item) {
        const done = beanWorkItemDone(item);
        return `
            <li class="hb-bean-work-item ${done ? 'hb-bean-work-item-done' : ''}" data-bean-work-id="${escapeAttr(item.id || '')}">
                <span class="hb-bean-work-checkbox" data-bean-work-checkbox aria-hidden="true">${done ? icons.checkCircle : ''}</span>
                <span data-bean-work-label>${escapeHtml(item.label || 'Bean work item')}</span>
            </li>`;
    }

    function beanWorkStatusActive() {
        if (Date.now() < beanWorkStatusHoldUntil && state.beanWorkItems.length > 0) return true;
        return state.busy
            || state.voiceListening
            || realtimeBackgroundWorkPending()
            || (state.chatRunState !== 'Ready' && state.beanWorkItems.length > 0)
            || state.beanWorkItems.some((item) => !beanWorkItemDone(item));
    }

    function beanWorkDisplayItems() {
        const items = state.beanWorkItems.filter((item) => item?.label);
        if (items.length) return items.slice(-6);
        return [];
    }

    function beanWorkStatusLabel(items = beanWorkDisplayItems()) {
        if (state.voiceListening) return state.voiceDraft ? 'Ready to send' : 'Listening';
        if (items.length && items.every((item) => beanWorkItemDone(item))) return 'Done';
        if (items.some((item) => !beanWorkItemDone(item)) || realtimeBackgroundWorkPending()) return 'Working...';
        const current = items.find((item) => !beanWorkItemDone(item));
        if (current) return current.label;
        return state.chatRunState && state.chatRunState !== 'Ready' ? state.chatRunState : 'Bean is ready';
    }

    function beanWorkItemDone(item) {
        return ['completed', 'succeeded', 'recorded', 'cancelled', 'failed', 'skipped'].includes(String(item?.status || '').toLowerCase());
    }

    function resetBeanWorkItems(label, status = 'running') {
        cancelBeanWorkStatusClear();
        stopBeanWorkEventPolling();
        state.beanWorkItems = [{ id: `turn-${Date.now()}`, label, status }];
        refreshBeanStatusTag();
    }

    function upsertBeanWorkItem(id, label, status = 'running', options = {}) {
        if (!id || !label) return;
        const normalizedStatus = String(status || 'running').toLowerCase();
        if (!beanWorkItemDone({ status: normalizedStatus })) {
            cancelBeanWorkStatusClear();
            beanWorkStatusMinUntil = Math.max(beanWorkStatusMinUntil, Date.now() + 700);
        }
        const existingIndex = state.beanWorkItems.findIndex((item) => item.id === id);
        const next = {
            id,
            label,
            status: normalizedStatus,
            ...(options.source ? { source: options.source } : {}),
            ...(options.resolvedByEvent ? { resolvedByEvent: true } : {}),
        };
        if (existingIndex >= 0) {
            state.beanWorkItems = state.beanWorkItems.map((item, index) => {
                if (index !== existingIndex) return item;
                if (item.resolvedByEvent && id === 'realtime-request' && options.source !== 'event' && !beanWorkItemDone(item)) {
                    return { ...item, status: beanWorkItemDone(item) ? item.status : normalizedStatus };
                }
                return { ...item, ...next, resolvedByEvent: Boolean(options.resolvedByEvent) };
            });
            if (state.beanWorkItems.every((item) => beanWorkItemDone(item))) scheduleBeanWorkStatusClear();
            refreshBeanStatusTag();
            return;
        }
        const placeholderIndex = beanWorkPlaceholderIndex(label);
        if (placeholderIndex >= 0 && options.source === 'event') {
            state.beanWorkItems = state.beanWorkItems.map((item, index) => index === placeholderIndex
                ? { ...item, label, status: normalizedStatus, resolvedByEvent: true, source: 'event' }
                : item);
            if (state.beanWorkItems.every((item) => beanWorkItemDone(item))) scheduleBeanWorkStatusClear();
            refreshBeanStatusTag();
            return;
        }
        if (isGenericBeanWorkLabel(label)) {
            refreshBeanStatusTag();
            return;
        }
        state.beanWorkItems = [...state.beanWorkItems, next].slice(-8);
        if (state.beanWorkItems.every((item) => beanWorkItemDone(item))) scheduleBeanWorkStatusClear();
        refreshBeanStatusTag();
    }

    function completeBeanWorkItem(id, label = '') {
        if (!id) return;
        const existingIndex = state.beanWorkItems.findIndex((item) => item.id === id);
        if (existingIndex >= 0) {
            state.beanWorkItems = state.beanWorkItems.map((item, index) => index === existingIndex ? { ...item, status: 'completed' } : item);
            if (state.beanWorkItems.every((item) => beanWorkItemDone(item))) scheduleBeanWorkStatusClear();
            refreshBeanStatusTag();
            return;
        }
        if (isGenericBeanWorkLabel(label)) {
            completeActiveBeanWorkItems();
            return;
        }
        if (label) upsertBeanWorkItem(id, label, 'completed');
    }

    function completeActiveBeanWorkItems() {
        if (!state.beanWorkItems.length) return;
        state.beanWorkItems = state.beanWorkItems.map((item) => beanWorkItemDone(item) ? item : { ...item, status: 'completed' });
        scheduleBeanWorkStatusClear();
        refreshBeanStatusTag();
    }

    function markActiveBeanWorkItems(status) {
        if (!state.beanWorkItems.length) return;
        state.beanWorkItems = state.beanWorkItems.map((item) => beanWorkItemDone(item) ? item : { ...item, status });
        if (state.beanWorkItems.every((item) => beanWorkItemDone(item))) scheduleBeanWorkStatusClear();
        refreshBeanStatusTag();
    }

    function scheduleBeanWorkStatusClear(delayMs = 1900) {
        if (state.busy || realtimeBackgroundWorkPending()) return;
        window.clearTimeout(beanWorkStatusClearTimer);
        const delay = Math.max(delayMs, beanWorkStatusMinUntil - Date.now(), 0);
        beanWorkStatusHoldUntil = Date.now() + delay;
        beanWorkStatusClearTimer = window.setTimeout(() => {
            beanWorkStatusClearTimer = 0;
            if (state.busy || realtimeBackgroundWorkPending()) {
                scheduleBeanWorkStatusClear(delayMs);
                return;
            }
            beanWorkStatusHoldUntil = 0;
            beanWorkStatusMinUntil = 0;
            state.beanWorkItems = [];
            refreshBeanStatusTag();
        }, delay);
    }

    function cancelBeanWorkStatusClear() {
        window.clearTimeout(beanWorkStatusClearTimer);
        beanWorkStatusClearTimer = 0;
        beanWorkStatusHoldUntil = 0;
    }

    function refreshBeanStatusTag() {
        if (state.phase !== 'signedIn') return;
        updateKioskVoicePillsInPlace();
    }

    function ensureRealtimeRequestWorkItem(content, status = 'running') {
        const label = beanWorkLabelForRequest(content);
        if (!label) return;
        const existing = state.beanWorkItems.find((item) => item.id === 'realtime-request');
        if (existing?.resolvedByEvent && !beanWorkItemDone(existing)) {
            refreshBeanStatusTag();
            return;
        }
        upsertBeanWorkItem('realtime-request', label, status);
    }

    function beanWorkPlaceholderIndex(label) {
        const eventCategory = beanWorkCategoryForLabel(label);
        return state.beanWorkItems.findIndex((item) => {
            if (item.id !== 'realtime-request' || item.resolvedByEvent || beanWorkItemDone(item)) return false;
            const placeholderCategory = beanWorkCategoryForLabel(item.label);
            return !eventCategory || !placeholderCategory || eventCategory === placeholderCategory;
        });
    }

    function beanWorkCategoryForLabel(label) {
        const text = String(label || '').toLowerCase();
        if (!text) return '';
        const action = /\b(?:delete|deleting|remove|removing|cancel|canceling|cancelled)\b/.test(text) ? 'delete'
            : /\b(?:create|creating|add|adding|schedule|scheduling)\b/.test(text) ? 'create'
            : /\b(?:update|updating|change|changing|move|moving|reschedule|rescheduling)\b/.test(text) ? 'update'
            : /\b(?:save|saving|remember|memory)\b/.test(text) ? 'save'
            : '';
        const target = /\b(?:calendar event|event|calendar|appointment|meeting)\b/.test(text) ? 'event'
            : /\b(?:reminder)\b/.test(text) ? 'reminder'
            : /\b(?:task|todo)\b/.test(text) ? 'task'
            : /\b(?:memory)\b/.test(text) ? 'memory'
            : '';
        return action || target ? `${action}:${target}` : '';
    }

    function isGenericBeanWorkLabel(label) {
        return /^(?:finish|finished|background work|finish background work|bean started working|read request|follow up on voice request|working on request)$/i.test(String(label || '').trim());
    }

    function beanWorkLabelForRequest(content) {
        const command = normalizedVoiceCommand(content);
        if (!command) return 'Working on request';
        const targetsEvent = /\b(?:calendar|event|events|appointment|appointments|meeting|meetings)\b/.test(command);
        const targetsTask = /\b(?:task|tasks|todo|to do)\b/.test(command);
        const targetsReminder = /\b(?:reminder|reminders|remind)\b/.test(command);
        if (/\b(?:delete|remove|cancel)\b/.test(command)) {
            if (targetsEvent) return 'Deleting event';
            if (targetsReminder) return 'Deleting reminder';
            if (targetsTask) return 'Deleting task';
            return 'Deleting item';
        }
        if (/\b(?:move|reschedule|update|change)\b/.test(command)) {
            if (targetsEvent) return 'Updating event';
            if (targetsReminder) return 'Updating reminder';
            if (targetsTask) return 'Updating task';
            return 'Updating item';
        }
        if (/\b(?:add|create|put|schedule)\b/.test(command)) {
            if (targetsEvent) return 'Creating event';
            if (targetsReminder) return 'Creating reminder';
            if (targetsTask) return 'Creating task';
            return 'Creating item';
        }
        if (/\b(?:complete|finish|mark)\b/.test(command)) {
            if (targetsTask) return 'Updating task';
            if (targetsReminder) return 'Updating reminder';
            return 'Updating item';
        }
        if (/\b(?:remember|memory)\b/.test(command)) return 'Saving memory';
        if (/\b(?:plan|organize|prioritize)\b/.test(command)) return 'Planning request';
        return 'Working on request';
    }

    function applyBeanWorkEvents(events = []) {
        normalizeList(events).forEach((event) => {
            const item = beanWorkItemFromEvent(event);
            if (!item) return;
            upsertBeanWorkItem(item.id, item.label, item.status, { source: 'event', resolvedByEvent: true });
        });
    }

    function beanWorkItemFromEvent(event) {
        const type = String(event?.event_type || event?.eventType || '');
        const status = String(event?.status || '').toLowerCase();
        const payload = event?.payload || {};
        const id = event?.id ? `event-${event.id}` : `${type}-${JSON.stringify(payload).slice(0, 80)}`;
        if (!type || type === 'runtime.run_queued') return null;
        if (type === 'runtime.run_started' || type === 'runtime.run_completed') return null;
        if (type === 'runtime.run_failed') return { id, label: 'Finish request', status: 'failed' };
        if (!type.startsWith('assistant.')) return null;
        const label = beanWorkEventLabel(type, payload);
        if (!label) return null;
        return { id, label, status: beanWorkEventStatus(status) };
    }

    function beanWorkEventStatus(status) {
        if (['failed', 'skipped', 'cancelled', 'succeeded', 'recorded', 'completed'].includes(status)) return status;
        return 'completed';
    }

    function beanWorkEventLabel(type, payload = {}) {
        const title = payload.title || payload.summary || payload.name || payload.reason || payload.display_name || payload.displayName || '';
        const readable = title ? `: ${String(title).slice(0, 72)}` : '';
        if (type.includes('.task.created')) return `Create task${readable}`;
        if (type.includes('.task.updated')) return `Update task${readable}`;
        if (type.includes('.task.deleted')) return `Delete task${readable}`;
        if (type.includes('.reminder.created')) return `Create reminder${readable}`;
        if (type.includes('.reminder.updated')) return `Update reminder${readable}`;
        if (type.includes('.reminder.deleted')) return `Delete reminder${readable}`;
        if (type.includes('.calendar_event.created')) return `Create calendar event${readable}`;
        if (type.includes('.calendar_event.updated')) return `Update calendar event${readable}`;
        if (type.includes('.calendar_event.deleted')) return `Delete calendar event${readable}`;
        if (type.includes('.approval.created')) return `Prepare approval${readable}`;
        if (type.includes('.blocker.created')) return `Flag blocker${readable}`;
        if (type.includes('.workspace_memory.noted')) return 'Save memory';
        if (type.includes('.google_calendar.')) return 'Sync Google Calendar';
        return null;
    }

    function startBeanWorkEventPolling(sessionId) {
        stopBeanWorkEventPolling();
        const token = ++beanWorkEventPollToken;
        const poll = async (attempt = 0) => {
            if (!sessionId || token !== beanWorkEventPollToken) return;
            try {
                const events = await api(`/assistant/sessions/${sessionId}/events`);
                if (token !== beanWorkEventPollToken) return;
                applyBeanWorkEvents(events);
                render();
            } catch (_) {}
            if (token !== beanWorkEventPollToken) return;
            if ((beanWorkStatusActive() || attempt < 3) && attempt < 50) {
                beanWorkEventPollTimer = window.setTimeout(() => poll(attempt + 1), 1600);
            }
        };
        beanWorkEventPollTimer = window.setTimeout(() => poll(0), 900);
    }

    function stopBeanWorkEventPolling() {
        window.clearTimeout(beanWorkEventPollTimer);
        beanWorkEventPollTimer = 0;
        beanWorkEventPollToken += 1;
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
                    <strong class="hb-chat-session-title">${escapeHtml(title)}</strong>
                    <span class="hb-spacer"></span>
                    <button class="hb-button-ghost hb-chat-history-toggle ${state.chatHistoryOpen ? 'hb-chat-history-toggle-active' : ''}" type="button" data-toggle-chat-history aria-expanded="${state.chatHistoryOpen ? 'true' : 'false'}">${icons.history}<span>History</span></button>
                    ${options.expandable ? `<button class="hb-button-secondary hb-chat-expand-action" type="button" data-toggle-chat-expand aria-label="${escapeAttr(expandLabel)}">${escapeHtml(expandLabel)}</button>` : ''}
                    <button class="hb-button-ghost hb-chat-new-session" type="button" data-new-session ${state.busy ? 'disabled' : ''}>${icons.add}<span>New</span></button>
                </div>
                ${errorMarkup(state.error)}
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
                <p>Answer a few quick questions so Bean can understand how to be as helpful as possible.</p>
            </article>`;
    }

    function onboardingCompletionMarkup() {
        const sessionMode = state.session?.runtime_mode || state.session?.runtimeMode || '';
        if (needsBeanOnboarding() || !(state.onboardingJustCompleted || sessionMode === 'onboarding')) return '';
        return `
            <article class="hb-chat-onboarding-card hb-chat-onboarding-complete">
                <div class="hb-chat-onboarding-kicker">${icons.checkCircle}<span>Onboarding saved</span></div>
                <p>Your preferences are saved. You can keep chatting or head back to the dashboard.</p>
                <div class="hb-message-actions">
                    <button class="hb-button" type="button" data-onboarding-dashboard>Go to dashboard</button>
                    <button class="hb-button-secondary" type="button" data-new-session>Start a new chat</button>
                </div>
            </article>`;
    }

    const onboardingTourSteps = [
        {
            target: 'bean',
            caption: 'Hold for voice to text, or tap to type',
        },
        {
            target: 'create',
            caption: 'Create new events, tasks, and reminders here',
        },
        {
            target: 'critical',
            caption: "Your critical count includes today's critical events, and tasks that have been marked critical, or are overdue",
        },
        {
            target: 'date-month',
            caption: 'These will snap you back to the current day or month at any point',
        },
    ];

    function onboardingTourStorageKey(user = state.user) {
        return `heybean.onboarding_tour_seen.${user?.id || 'anonymous'}`;
    }

    function onboardingTourSeen() {
        try {
            return localStorage.getItem(onboardingTourStorageKey()) === 'true';
        } catch (_) {
            return false;
        }
    }

    function markOnboardingTourSeen() {
        try {
            localStorage.setItem(onboardingTourStorageKey(), 'true');
        } catch (_) {
            // A blocked storage write should not trap the user in the tour.
        }
    }

    function startOnboardingTourIfNeeded() {
        if (needsBeanOnboarding() || onboardingTourSeen()) return;
        state.onboardingTourStep = 0;
        state.onboardingTourActive = true;
    }

    function closeOnboardingTour() {
        markOnboardingTourSeen();
        state.onboardingTourActive = false;
        state.onboardingTourStep = 0;
    }

    function onboardingTourMarkup() {
        if (!state.onboardingTourActive) return '';
        const step = onboardingTourSteps[Math.min(state.onboardingTourStep, onboardingTourSteps.length - 1)];
        const isLast = state.onboardingTourStep >= onboardingTourSteps.length - 1;
        return `
            <section class="hb-onboarding-tour hb-onboarding-tour-${escapeAttr(step.target)}" role="dialog" aria-modal="true" aria-live="polite" aria-label="HeyBean tour">
                <div class="hb-onboarding-tour-highlight" data-tour-highlight="${escapeAttr(step.target)}" aria-hidden="true"></div>
                <article class="hb-onboarding-tour-card">
                    <p>${escapeHtml(step.caption)}</p>
                    <div class="hb-onboarding-tour-actions">
                        <button class="hb-button-ghost" type="button" data-onboarding-tour-skip>Skip</button>
                        <button class="hb-button" type="button" ${isLast ? 'data-onboarding-tour-finish' : 'data-onboarding-tour-next'}>${isLast ? 'Finish' : 'Next'}</button>
                    </div>
                </article>
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

    function topBeanControlsMarkup() {
        return `
            <div class="hb-topbar-bean-controls">
                <button class="hb-bean-button hb-topbar-bean-button ${state.chatExpanded || state.selected === 'bean' ? 'hb-bean-button-active' : ''}" type="button" data-toggle-chat-expand aria-label="${state.chatExpanded ? 'Close Bean chat' : 'Open Bean chat'}" title="Bean chat">
                    <img src="${escapeAttr(logoUrl)}" alt="">
                </button>
                ${kioskVoicePillMarkup({ topbar: true, workStatus: true })}
            </div>`;
    }

    function kioskVoiceStatusTagModel(options = {}) {
        const requested = state.kioskVoiceEnabled;
        const ready = kioskVoiceReady();
        const phase = ready ? (state.kioskVoicePhase === 'idle' ? 'armed' : state.kioskVoicePhase || 'armed') : 'disabled';
        const workItems = options.workStatus ? beanWorkDisplayItems() : [];
        const workActive = options.workStatus && (
            workItems.length > 0
            || realtimeBackgroundWorkPending()
            || (Date.now() < beanWorkStatusHoldUntil && state.beanWorkItems.length > 0)
        );
        const completedCount = workItems.filter((item) => beanWorkItemDone(item)).length;
        const voiceLabel = kioskVoicePillLabel({ requested, ready, phase });
        const label = workActive ? beanWorkStatusLabel(workItems) : voiceLabel;
        const cancelable = ready && kioskVoicePillIsCancelable(phase);
        const actionLabel = kioskVoicePillActionLabel({ ready, phase, label });
        return {
            actionLabel,
            cancelable,
            completedCount,
            label,
            phase: workActive ? 'working' : phase,
            ready,
            workActive,
            workItems,
        };
    }

    function kioskVoicePillMarkup(options = {}) {
        const model = kioskVoiceStatusTagModel(options);
        const workListClass = `hb-bean-work-list hb-kiosk-voice-work-list ${model.workItems.length ? '' : 'hb-kiosk-voice-work-list-empty'}`.trim();
        return `
            <div class="hb-kiosk-voice-status-shell ${model.workActive ? 'hb-kiosk-voice-status-shell-working' : ''}">
                <button class="hb-kiosk-voice-pill hb-kiosk-voice-pill-button hb-kiosk-voice-pill-${escapeAttr(model.phase)} ${model.cancelable ? 'hb-kiosk-voice-pill-cancelable' : ''} ${options.standalone ? 'hb-kiosk-voice-pill-standalone' : ''} ${options.topbar ? 'hb-kiosk-voice-pill-topbar' : ''}" type="button" data-toggle-kiosk-voice aria-live="polite" aria-label="${escapeAttr(model.actionLabel)}" title="${escapeAttr(model.actionLabel)}" aria-pressed="${model.ready}">
                    <span class="hb-kiosk-voice-pill-icon" aria-hidden="true">${icons.mic}</span>
                    <span class="hb-kiosk-voice-pill-label">${escapeHtml(model.label)}</span>
                    ${model.workItems.length ? `<span class="hb-kiosk-voice-work-count">${escapeHtml(`${model.completedCount}/${model.workItems.length}`)}</span>` : ''}
                </button>
                ${beanWorkListMarkup(model.workItems, workListClass)}
            </div>`;
    }

    function kioskVoicePillLabel({ requested, ready, phase }) {
        if (ready) {
            if (phase === 'armed' && !state.kioskVoiceMessage) return 'Say hey bean';
            return state.kioskVoiceMessage || phase;
        }
        if (!requested) return 'Enable microphone to chat';
        return state.kioskVoiceMessage || 'Connecting';
    }

    function kioskVoicePillIsCancelable(phase = state.kioskVoicePhase) {
        if (!state.kioskVoiceEnabled || !kioskRealtimeConnected()) return false;
        if (realtimeBackgroundWorkPending() || realtimeAssistantOutputActive() || kioskRealtimeResponseTimer) return true;
        return kioskConversationActive && ['heard', 'listening', 'working', 'responding', 'speaking'].includes(phase);
    }

    function kioskVoicePillActionLabel({ ready, phase, label }) {
        if (ready && kioskVoicePillIsCancelable(phase)) return 'Cancel voice request';
        if (ready) return 'Turn off kiosk voice';
        return label;
    }

    function settingsMarkup() {
        const user = state.user || {};
        const prefs = user.notification_preferences || {};
        const profile = currentAgentProfile();
        const priorities = profilePriorities(profile);
        const context = profileOnboardingContext(profile);
        const homeCity = profileHomeCity(profile);
        const complete = profilePreferencesReady(profile);
        const workspaceItems = workspaces();
        const activeWorkspaceId = String(currentWorkspaceId() || '');
        return `
            <section class="hb-card hb-card-pad hb-settings-grid">
                ${sectionTitle(icons.settings, 'Settings', 'Focused Hermes Bean preferences')}
                ${errorMarkup(state.error)}
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
                <form class="hb-surface-soft hb-card-pad hb-settings-section hb-home-city-settings" data-home-city-form>
                    ${settingsSectionHeader(icons.spaces, 'Home city', homeCity || 'Used for weather and local context.')}
                    <div class="hb-field-row" style="margin-top:10px">
                        ${labelInput('Home city', 'homeCity', 'text', homeCity, 'autocomplete="address-level2" maxlength="120"')}
                    </div>
                    <div class="hb-row-actions">
                        <button class="hb-button-secondary" type="button" data-clear-home-city ${homeCity ? '' : 'disabled'}>Clear</button>
                        <button class="hb-button" type="submit">Save</button>
                    </div>
                </form>
                ${themeSettingsMarkup()}
                ${settingsCategoriesMarkup()}
                <div class="hb-surface-soft hb-card-pad hb-settings-section">
                    ${settingsSectionHeader(icons.bell, 'Notifications', 'Choose how reminders can reach you.')}
                    <label class="hb-switch-row"><input type="checkbox" data-pref="reminder_push" ${prefs.reminder_push !== false ? 'checked' : ''}> Reminder push notifications</label>
                    <label class="hb-switch-row"><input type="checkbox" data-pref="reminder_email" ${prefs.reminder_email === true ? 'checked' : ''}> Reminder emails</label>
                </div>
                <div class="hb-surface-soft hb-card-pad hb-settings-section">
                    ${settingsSectionHeader(icons.spaces, 'Workspaces', 'Switch the space Bean uses for calendar, tasks, and reminders.')}
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
                <div class="hb-surface-soft hb-card-pad hb-settings-section">
                    ${googleCalendarMarkup()}
                </div>
                <div class="hb-surface-soft hb-card-pad hb-settings-section">
                    ${settingsSectionHeader(icons.calendar, 'Calendar preferences', 'Day view visible hours.')}
                    <div class="hb-field-row hb-settings-hour-row" style="margin-top:10px">
                        ${settingsHourSelectMarkup('Start hour', 'startHour', Number(localStorage.getItem('heybean.calendar.startHour') || 6), 0, 23)}
                        ${settingsHourSelectMarkup('End hour', 'endHour', Number(localStorage.getItem('heybean.calendar.endHour') || 22), 1, 24)}
                    </div>
                </div>
                ${billingSettingsMarkup()}
                <div class="hb-card hb-card-pad hb-settings-section">
                    ${settingsSectionHeader(icons.user, 'Account controls', 'Export, sign out, or permanently delete your account.')}
                    <div class="hb-account-actions">
                        <button class="hb-button-secondary" type="button" data-export-account>Export data</button>
                        <button class="hb-button-secondary" type="button" data-logout>Sign out</button>
                        <button class="hb-button-danger" type="button" data-delete-account>Delete account</button>
                    </div>
                </div>
                <div class="hb-settings-legal-row">
                    <a href="/privacy">Privacy</a>
                    <a href="/terms">Terms</a>
                    <a href="/support">Support</a>
                </div>
            </section>`;
    }

    function settingsCategoriesMarkup() {
        const categories = categoryOptions();
        const selected = selectedSettingsCategory(categories);
        const selectedId = String(selected?.id || selected?.name || '');
        return `
            <div class="hb-surface-soft hb-card-pad hb-settings-section hb-settings-categories" data-settings-category-panel>
                <div class="hb-settings-panel-head">
                    <span class="hb-compact-icon">${icons.tune}</span>
                    <div>
                        <strong>Categories</strong>
                        <small>${categories.length ? `${categories.length} saved ${categories.length === 1 ? 'category' : 'categories'}` : 'Create categories for events, tasks, and reminders.'}</small>
                    </div>
                </div>
                ${categories.length ? `
                    <label class="hb-label hb-settings-category-picker">Category
                        <select class="hb-select" data-settings-category-select>
                            ${categories.map((category) => `<option value="${escapeAttr(category.id || category.name)}" ${String(category.id || category.name) === selectedId ? 'selected' : ''}>${escapeHtml(category.name)}</option>`).join('')}
                        </select>
                    </label>
                    <form class="hb-settings-category-form" data-settings-category-form="${escapeAttr(selectedId)}">
                        <span class="hb-color-swatch" style="background:${escapeAttr(safeColor(selected?.color || themeAccentColor()))}"></span>
                        <input class="hb-input" name="name" value="${escapeAttr(selected?.name || '')}" aria-label="Category name" required>
                        <input class="hb-input hb-color-input" type="color" name="color" value="${escapeAttr(safeColor(selected?.color || themeAccentColor()))}" aria-label="Category color">
                        <button class="hb-button-secondary" type="submit">Save</button>
                        <button class="hb-button-danger" type="button" data-settings-category-delete="${escapeAttr(selectedId)}">Delete</button>
                    </form>
                ` : '<div class="hb-empty">No categories yet.</div>'}
                <div class="hb-account-actions">
                    <button class="hb-button-secondary" type="button" data-open-settings-categories>Add category</button>
                </div>
            </div>`;
    }

    function selectedSettingsCategory(categories = categoryOptions()) {
        if (!categories.length) return null;
        const selectedId = String(state.settingsCategoryId || '');
        return categories.find((category) => String(category.id || category.name) === selectedId) || categories[0];
    }

    function settingsHourSelectMarkup(label, key, value, min, max) {
        const current = Math.max(min, Math.min(max, Number.isFinite(value) ? value : min));
        return `
            <label class="hb-label">${escapeHtml(label)}
                <select class="hb-select" data-calendar-pref="${escapeAttr(key)}">
                    ${Array.from({ length: max - min + 1 }, (_, index) => min + index).map((hour) => `<option value="${hour}" ${hour === current ? 'selected' : ''}>${escapeHtml(hourLabel(hour))}</option>`).join('')}
                </select>
            </label>`;
    }

    function billingSettingsMarkup() {
        const subscription = state.subscriptionSummary || {};
        const user = state.user || {};
        const tier = String(subscription.tier || user.subscription_tier || user.subscriptionTier || 'base').toLowerCase();
        const status = String(subscription.status || user.subscription_status || user.subscriptionStatus || '').toLowerCase();
        const cancelAtPeriodEnd = Boolean(subscription.cancel_at_period_end || subscription.cancelAtPeriodEnd);
        const trialEndsAt = subscription.trial_ends_at || subscription.trialEndsAt || user.subscription_trial_ends_at || user.subscriptionTrialEndsAt || '';
        const currentPeriodEnd = subscription.current_period_end || subscription.currentPeriodEnd || '';
        const accessEndsAt = subscription.access_ends_at || subscription.accessEndsAt || (cancelAtPeriodEnd ? currentPeriodEnd : '');
        const paymentMethod = state.billingPaymentMethod;
        const selectedPlan = subscriptionPlans[tier] ? tier : 'base';
        const canCancel = subscription.can_cancel === true || subscription.canCancel === true;
        const canResume = subscription.can_resume === true
            || subscription.canResume === true
            || (cancelAtPeriodEnd && accessEndsAt && new Date(accessEndsAt) > new Date());
        const cancelDisabled = state.billingBusy || !canCancel;
        return `
            <div class="hb-surface-soft hb-card-pad hb-settings-section hb-billing-settings" data-billing-settings>
                <div class="hb-billing-header">
                    <span class="hb-compact-icon">${icons.activity}</span>
                    <div>
                        <strong>Billing</strong>
                        <small>${escapeHtml(billingStatusLine(selectedPlan, status, cancelAtPeriodEnd))}</small>
                    </div>
                </div>
                <div class="hb-billing-summary-grid">
                    <div><span>Plan</span><strong>${escapeHtml(subscriptionPlans[selectedPlan]?.label || 'Base')}</strong></div>
                    <div><span>${cancelAtPeriodEnd ? 'Access ends' : 'Renewal'}</span><strong>${escapeHtml(billingRenewalLine(trialEndsAt, currentPeriodEnd, cancelAtPeriodEnd, accessEndsAt))}</strong></div>
                    <div><span>Payment</span><strong>${escapeHtml(paymentMethodDisplayLine(paymentMethod))}</strong></div>
                </div>
                <div class="hb-billing-plan-row">
                    <label class="hb-label">Change plan
                        <select class="hb-select" data-billing-plan-select ${state.billingBusy ? 'disabled' : ''}>
                            ${Object.entries(subscriptionPlans).map(([key, plan]) => `<option value="${escapeAttr(key)}" ${key === selectedPlan ? 'selected' : ''}>${escapeHtml(plan.label)} ${escapeHtml(plan.price)}/mo</option>`).join('')}
                        </select>
                    </label>
                    <button class="hb-button" type="button" data-billing-change-plan ${state.billingBusy ? 'disabled' : ''}>${state.billingBusy ? 'Working...' : 'Change plan'}</button>
                </div>
                ${cancelAtPeriodEnd ? `<p class="hb-item-meta"><strong>Renewal is canceled.</strong> ${accessEndsAt ? `Your access stays active through ${escapeHtml(formatDateOnly(accessEndsAt))}. You can restart renewal before then to keep this account and data active.` : 'Your access stays active through the end of the current paid period or trial.'}</p>` : ''}
                <div class="hb-account-actions">
                    <button class="hb-button-secondary" type="button" data-billing-update-payment ${state.billingBusy ? 'disabled' : ''}>Update payment</button>
                    <button class="hb-button-secondary" type="button" data-billing-refresh ${state.billingBusy || state.billingPaymentLoading ? 'disabled' : ''}>${state.billingPaymentLoading ? 'Refreshing...' : 'Refresh billing'}</button>
                    ${canResume ? `<button class="hb-button" type="button" data-billing-resume-subscription ${state.billingBusy ? 'disabled' : ''}>${state.billingBusy ? 'Working...' : 'Restart renewal'}</button>` : ''}
                    <button class="hb-button-danger" type="button" data-billing-cancel-renewal ${cancelDisabled ? 'disabled' : ''}>${cancelAtPeriodEnd ? 'Renewal canceled' : 'Cancel renewal'}</button>
                </div>
                ${state.billingError ? `<div class="hb-error"><div><strong>Billing needs attention</strong><span>${escapeHtml(state.billingError)}</span></div></div>` : ''}
                ${state.billingMessage ? `<div class="hb-success"><strong>${escapeHtml(state.billingMessage)}</strong></div>` : ''}
                <p class="hb-item-meta">Stripe securely handles payment processing. HeyBean stores only subscription status and safe payment summaries.</p>
            </div>`;
    }

    function billingStatusLine(planKey, status, cancelAtPeriodEnd) {
        const plan = subscriptionPlans[planKey]?.label || 'Base';
        if (cancelAtPeriodEnd) return `${plan} plan, renewal canceled`;
        if (!status) return `Current plan: ${plan}`;
        return `Current plan: ${plan} • ${status.replaceAll('_', ' ')}`;
    }

    function billingRenewalLine(trialEndsAt, currentPeriodEnd, cancelAtPeriodEnd, accessEndsAt = '') {
        if (cancelAtPeriodEnd && accessEndsAt) return `Last day: ${formatDateOnly(accessEndsAt)}`;
        if (cancelAtPeriodEnd) return 'Canceled at period end';
        if (trialEndsAt) return `Trial through ${formatDateTime(trialEndsAt)}`;
        if (currentPeriodEnd) return `Renews around ${formatDateTime(currentPeriodEnd)}`;
        return 'Monthly';
    }

    function paymentMethodDisplayLine(paymentMethod) {
        if (state.billingPaymentLoading) return 'Loading payment method...';
        if (!paymentMethod) return 'No saved payment method yet';
        const brand = String(paymentMethod.brand || paymentMethod.type || 'Card');
        const last4 = paymentMethod.last4 ? ` ending in ${paymentMethod.last4}` : '';
        const expiry = paymentMethod.exp_month || paymentMethod.expMonth
            ? `, expires ${paymentMethod.exp_month || paymentMethod.expMonth}/${paymentMethod.exp_year || paymentMethod.expYear}`
            : '';
        return `${capitalize(brand)}${last4}${expiry}`;
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

    function topCreateMenuMarkup() {
        return `
            <details class="hb-create-menu" data-create-menu>
                <summary class="hb-icon-button hb-topbar-action hb-create-trigger" aria-label="Create new item" title="Create">${icons.add}</summary>
                <div class="hb-create-popover">
                    <button class="hb-overflow-action" type="button" data-open-create="event">${icons.calendar}<span>New event</span></button>
                    <button class="hb-overflow-action" type="button" data-open-create="task">${icons.tasks}<span>New task</span></button>
                    <button class="hb-overflow-action" type="button" data-open-create="reminder">${icons.reminders}<span>New reminder</span></button>
                </div>
            </details>`;
    }

    function topProfileMenuMarkup() {
        const user = state.user || {};
        const name = user.name || 'Account';
        const email = user.email || '';
        const workspaceItems = workspaces();
        const activeWorkspaceId = String(currentWorkspaceId() || '');
        const activeWorkspace = workspaceItems.find((workspace) => String(workspace.id) === activeWorkspaceId || workspace.active || workspace.is_default || workspace.isDefault) || workspaceItems[0];
        return `
            <details class="hb-profile-menu">
                <summary class="hb-profile-trigger" aria-label="${escapeAttr(`Account menu for ${name}`)}" title="Account menu">
                    <span class="hb-avatar" aria-hidden="true">${escapeHtml(userInitials(name, email))}</span>
                </summary>
                <div class="hb-profile-popover">
                    ${userIsAdmin() ? `<button class="hb-profile-action ${state.selected === 'admin' ? 'hb-profile-action-active' : ''}" type="button" data-nav="admin">${icons.activity}<span>Admin monitor</span></button>` : ''}
                    ${workspaceItems.length > 1 ? `<label class="hb-profile-workspace"><span>${icons.spaces}<strong>Workspace</strong></span><select data-top-workspace-select aria-label="Switch workspace">${workspaceItems.map((workspace) => `<option value="${escapeAttr(workspace.id)}" ${String(workspace.id) === String(activeWorkspace?.id) ? 'selected' : ''}>${escapeHtml(workspaceDisplayName(workspace))}</option>`).join('')}</select></label>` : ''}
                    <button class="hb-profile-action" type="button" data-refresh-app ${state.calendarRefreshing ? 'disabled' : ''}>${state.calendarRefreshing ? '<span class="hb-spinner hb-spinner-tiny"></span>' : icons.refresh}<span>Refresh</span></button>
                    <button class="hb-profile-action ${state.selected === 'settings' ? 'hb-profile-action-active' : ''}" type="button" data-nav="settings">${icons.settings}<span>Settings</span></button>
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
        const color = itemColor(event);
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
        return `
            <nav class="hb-bottom-menu" aria-label="App navigation">
                <div class="hb-bottom-bar">
                    ${nav.slice(0, 2).map(navButton).join('')}
                    <span class="hb-bottom-bar-center-spacer" aria-hidden="true"></span>
                    ${nav.slice(2).map(navButton).join('')}
                </div>
                ${mobileBeanButtonMarkup()}
            </nav>`;
    }

    function mobileBeanButtonMarkup() {
        const active = state.selected === 'bean' || state.chatExpanded;
        const listening = state.voiceListening;
        return `
            ${beanWorkStatusMarkup({ mobile: true })}
            <button class="hb-bean-button hb-mobile-bean-button ${active ? 'hb-bean-button-active' : ''} ${listening ? 'hb-bean-button-listening' : ''}" type="button" data-mobile-bean-button aria-label="Bean chat. Hold to dictate, tap to type." title="Bean">
                <img src="${escapeAttr(logoUrl)}" alt="">
            </button>`;
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
        const gutterWidth = timelineGutterWidth();
        const currentTimeMarker = currentTimeMarkerMarkup(days, startHour, endHour);
        return `
            <div class="hb-timeline hb-timeline-multi-day" data-timeline-start-hour="${startHour}" data-timeline-end-hour="${endHour}" style="--hb-hour-count:${hours.length};--hb-day-count:${days.length};--hb-day-min-width:${minDayWidth}px;--hb-timeline-min-width:${gutterWidth + (days.length * minDayWidth)}px" aria-label="${escapeAttr(calendarRangeLabel(days))} timeline">
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
        const top = Math.max(0, minutesFromStart / 60 * timelineHourHeight());
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
        const color = itemColor(event);
        return `
            <button class="hb-month-all-day-event" type="button" data-edit-event="${event.id}" style="background:${hexAlpha(color, .12)};border-color:${hexAlpha(color, .30)}">
                <span class="hb-month-event-title">${criticalStarMarkup(event)}${escapeHtml(event.title || event.name || 'Untitled')}</span>
            </button>`;
    }

    function monthMultiDayEventMarkup(event, day) {
        const color = itemColor(event);
        const time = multiDayEventDayTime(event, day, { showEndTime: false });
        return `
            <button class="hb-month-all-day-event hb-month-multi-day-event" type="button" data-edit-event="${event.id}" style="background:${hexAlpha(color, .12)};border-color:${hexAlpha(color, .30)}">
                ${time ? `<span class="hb-month-event-time">${escapeHtml(time)}</span>` : ''}
                <span class="hb-month-event-title">${criticalStarMarkup(event)}${escapeHtml(event.title || event.name || 'Untitled')}</span>
            </button>`;
    }

    function monthEventMarkup(event) {
        const color = itemColor(event);
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
        const days = itemBoardDays();
        const visibleDaySet = new Set(days);
        const futureLabel = kind === 'task' ? 'Future tasks' : 'Future reminders';
        const futureEmptyText = kind === 'task' ? 'No future tasks' : 'No future reminders';
        const futureItems = items
            .filter((item) => !visibleDaySet.has(itemBoardDateOnly(item, kind)))
            .sort(itemSortFunction(kind));
        return `
            <div class="hb-day-board-shell">
                <div class="hb-day-board" aria-label="${escapeAttr(kind === 'task' ? 'Tasks by day' : 'Reminders by day')}">
                    ${days.map((day) => dayBoardColumnMarkup(day, itemsForItemDay(items, kind, day), kind, emptyText)).join('')}
                </div>
                ${dayBoardColumnMarkup(null, futureItems, kind, futureEmptyText, futureLabel, 'hb-day-board-column-all')}
            </div>`;
    }

    function dayBoardColumnMarkup(day, items, kind, emptyText, overrideLabel = '', extraClass = '') {
        const label = overrideLabel || (day ? glanceDayLabel(parseLocalDate(day)) : 'No date');
        const listMarkup = kind === 'task'
            ? taskListWithFutureBucketsMarkup(items, emptyText)
            : items.length ? items.map((item) => itemMarkup(item, kind)).join('') : `<div class="hb-empty hb-surface-soft">${escapeHtml(emptyText)}</div>`;
        return `
            <section class="hb-day-board-column ${day ? '' : 'hb-day-board-column-unscheduled'} ${extraClass}" aria-label="${escapeAttr(label)}">
                <div class="hb-day-board-head">
                    <strong>${escapeHtml(label)}</strong>
                    <span>${escapeHtml(itemCountLabel(items.length, kind))}</span>
                </div>
                <div class="hb-list hb-day-board-list">
                    ${listMarkup}
                </div>
            </section>`;
    }

    function taskListWithFutureBucketsMarkup(items, emptyText) {
        const buckets = taskFutureBuckets(items);
        const visibleMarkup = buckets.now.map((item) => itemMarkup(item, 'task')).join('');
        const sevenMarkup = taskFutureBucketMarkup('seven', 'More than 7 days away', buckets.seven);
        const thirtyMarkup = taskFutureBucketMarkup('thirty', 'More than 30 days away', buckets.thirty);
        const hasItems = buckets.now.length || buckets.seven.length || buckets.thirty.length;
        return hasItems
            ? `${visibleMarkup}${sevenMarkup}${thirtyMarkup}`
            : `<div class="hb-empty hb-surface-soft">${escapeHtml(emptyText)}</div>`;
    }

    function taskFutureBucketMarkup(bucket, label, items) {
        if (!items.length) return '';
        const open = Boolean(state.futureTaskBucketsOpen?.[bucket]);
        return `
            <div class="hb-task-future-bucket" data-task-future-bucket="${escapeAttr(bucket)}">
                <button class="hb-task-future-toggle" type="button" data-toggle-task-future="${escapeAttr(bucket)}" aria-expanded="${open ? 'true' : 'false'}">
                    <span class="hb-task-future-chevron">${open ? '▲' : '▼'}</span>
                    <span>${escapeHtml(label)}</span>
                    <strong>${escapeHtml(itemCountLabel(items.length, 'task'))}</strong>
                </button>
                ${open ? `<div class="hb-list hb-task-future-list">${items.map((item) => itemMarkup(item, 'task')).join('')}</div>` : ''}
            </div>`;
    }

    function taskFutureBuckets(items) {
        const buckets = { now: [], seven: [], thirty: [] };
        items.forEach((item) => {
            const daysAway = taskDaysAway(item);
            if (daysAway !== null && daysAway > 30) {
                buckets.thirty.push(item);
            } else if (daysAway !== null && daysAway > 7) {
                buckets.seven.push(item);
            } else {
                buckets.now.push(item);
            }
        });
        return buckets;
    }

    function taskDaysAway(task) {
        const value = task?.due_at || task?.dueAt || '';
        if (!value) return null;
        const due = parseLocalDate(value);
        if (Number.isNaN(due.getTime())) return null;
        const today = parseLocalDate(dateOnly(new Date()));
        const dueDay = parseLocalDate(dateOnly(due));
        return Math.round((dueDay - today) / 86400000);
    }

    function itemMarkup(item, kind) {
        const completed = kind === 'task' ? taskCompleted(item) : reminderCompleted(item);
        const color = itemColor(item);
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
        const color = itemColor(event);
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
        const color = itemColor(event);
        const shortClass = style.minutes <= 30 ? ' hb-timed-event-short' : '';
        return `
            <button class="hb-event hb-timed-event${shortClass}" type="button" data-edit-event="${event.id}" style="${style.css};background:${hexAlpha(color, .12)};border-color:${hexAlpha(color, .30)}" data-duration-minutes="${style.minutes}">
                <div class="hb-event-time">${escapeHtml(eventStartTime(event))}</div>
                <div class="hb-event-title">${criticalStarMarkup(event)}${escapeHtml(event.title || event.name || 'Untitled')}</div>
            </button>`;
    }

    function allDayEventMarkup(event) {
        const color = itemColor(event);
        return `<button class="hb-all-day-event" type="button" data-edit-event="${event.id}" style="background:${hexAlpha(color, .12)};border-color:${hexAlpha(color, .30)}">${criticalStarMarkup(event)}${escapeHtml(event.title || event.name || 'Untitled')}</button>`;
    }

    function multiDayEventMarkup(event, day) {
        const color = itemColor(event);
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
        const stepAttr = (type === 'datetime-local' || type === 'time') && !/\bstep\s*=/.test(attrs)
            ? 'step="300" '
            : '';
        return `<label class="hb-label">${escapeHtml(label)}<input class="hb-input" type="${type}" name="${escapeAttr(name)}" value="${escapeAttr(value)}" placeholder="${escapeAttr(label)}" ${stepAttr}${attrs}></label>`;
    }

    function modalMarkup(modal) {
        if (modal.type === 'register-early-access-success') return registerEarlyAccessSuccessModalMarkup();
        if (modal.type === 'issue-report') return issueReportModalMarkup();
        if (modal.type === 'issue-report-success') return issueReportSuccessModalMarkup();
        if (modal.type === 'admin-usage-log') return adminUsageLogModalMarkup(modal.log);
        if (modal.type === 'admin-command-run') return adminCommandRunModalMarkup(modal);
        if (modal.type === 'profile') return profileModalMarkup();
        if (modal.type === 'agent') return agentModalMarkup();
        if (modal.type === 'workspace') return workspaceModalMarkup(modal.mode, modal.workspace);
        if (modal.type === 'categories') return categoriesModalMarkup();
        if (modal.type === 'recurring-delete') return recurringDeleteModalMarkup(modal.item);
        return itemModalMarkup(modal.type, modal.item, modal.parentTask);
    }

    function registerEarlyAccessSuccessModalMarkup() {
        return `
            <div class="hb-modal-backdrop" role="dialog" aria-modal="true" aria-labelledby="register-success-title">
                <section class="hb-card hb-modal hb-register-success-modal">
                    <div class="hb-register-success-icon" aria-hidden="true">${icons.checkCircle}</div>
                    <h2 id="register-success-title">Thank you for signing up for early access!</h2>
                    <p>We look forward to onboarding you soon!</p>
                    <div class="hb-modal-actions hb-issue-report-success-actions">
                        <button class="hb-button" type="button" data-register-early-access-home>Done</button>
                    </div>
                </section>
            </div>`;
    }

    function adminUsageLogModalMarkup(log = {}) {
        const user = log.user || {};
        const workspace = log.workspace || {};
        const prompt = log.input_prompt_full || log.inputPromptFull || log.request_full || log.requestFull || log.request_preview || log.requestPreview || 'No request captured for this usage log.';
        const userRequest = log.request_full || log.requestFull || log.request_preview || log.requestPreview || '';
        const actions = normalizeList(log.action_types || log.actionTypes);
        const metadata = log.metadata && typeof log.metadata === 'object'
            ? JSON.stringify(log.metadata, null, 2)
            : '';
        return `
            <div class="hb-modal-backdrop" role="dialog" aria-modal="true" aria-label="AI usage log details">
                <section class="hb-card hb-modal hb-admin-log-modal">
                    ${sectionTitle(icons.activity, 'AI usage log', 'Full user input and run details')}
                    <div class="hb-admin-log-detail-grid">
                        ${adminLogDetailItemMarkup('When', formatDateTime(log.created_at || log.createdAt))}
                        ${adminLogDetailItemMarkup('User', user.name || user.email || `#${log.user_id || log.userId || ''}`)}
                        ${adminLogDetailItemMarkup('Workspace', workspace.name || (log.workspace_id || log.workspaceId ? `#${log.workspace_id || log.workspaceId}` : 'None'))}
                        ${adminLogDetailItemMarkup('Model', log.model || 'unknown')}
                        ${adminLogDetailItemMarkup('Route', log.route_tier || log.routeTier || 'unknown')}
                        ${adminLogDetailItemMarkup('Status', log.status || 'logged')}
                        ${adminLogDetailItemMarkup('Tokens', formatTokens(log.total_tokens || log.totalTokens))}
                        ${adminLogDetailItemMarkup('Cost', formatCurrency(log.estimated_cost_usd || log.estimatedCostUsd))}
                    </div>
                    <div class="hb-admin-log-prompt-block">
                        <strong>Full input prompt</strong>
                        <pre>${escapeHtml(prompt)}</pre>
                    </div>
                    ${userRequest && userRequest !== prompt ? `<div class="hb-admin-log-prompt-block"><strong>User request</strong><pre>${escapeHtml(userRequest)}</pre></div>` : ''}
                    ${actions.length ? `<div class="hb-admin-log-prompt-block"><strong>Actions/tools</strong><pre>${escapeHtml(actions.join('\\n'))}</pre></div>` : ''}
                    ${metadata ? `<details class="hb-admin-log-metadata"><summary>Metadata</summary><pre>${escapeHtml(metadata)}</pre></details>` : ''}
                    <div class="hb-modal-actions">
                        <button class="hb-button-secondary" type="button" data-close-modal>Close</button>
                    </div>
                </section>
            </div>`;
    }

    function adminLogDetailItemMarkup(label, value) {
        return `<span><small>${escapeHtml(label)}</small><strong>${escapeHtml(value || 'None')}</strong></span>`;
    }

    function adminCommandRunModalMarkup(modal = {}) {
        const running = adminCommandRunActive(modal.status);
        const result = modal.result || {};
        const metadata = result.metadata || {};
        const output = result.output || (running ? 'Starting command...\nLive output will appear here as the server receives it.' : '');
        const error = result.error || modal.error || '';
        const exitCode = result.exit_code ?? result.exitCode;
        const command = metadata.command_line || metadata.commandLine || normalizeList(result.command).join(' ') || 'admin command';

        return `
            <div class="hb-modal-backdrop" role="dialog" aria-modal="true" aria-label="Admin command activity">
                <section class="hb-card hb-modal hb-admin-log-modal">
                    ${sectionTitle(icons.activity, result.command_label || result.commandLabel || 'Admin command', running ? 'Running on the server' : 'Command activity and result')}
                    <div class="hb-admin-log-detail-grid">
                        ${adminLogDetailItemMarkup('Command', command)}
                        ${adminLogDetailItemMarkup('Status', result.status || modal.status || 'queued')}
                        ${adminLogDetailItemMarkup('Exit code', running ? 'pending' : exitCode ?? 'unknown')}
                        ${adminLogDetailItemMarkup('Started', formatDateTime(result.started_at || result.startedAt) || (running ? 'pending' : 'unknown'))}
                        ${adminLogDetailItemMarkup('Finished', formatDateTime(result.finished_at || result.finishedAt) || (running ? 'running' : 'unknown'))}
                        ${adminLogDetailItemMarkup('Timeout', metadata.timeout ? `${metadata.timeout}s` : 'default')}
                        ${adminLogDetailItemMarkup('Workdir', metadata.cwd || 'default')}
                        ${adminLogDetailItemMarkup('Run id', result.id || modal.runId || 'pending')}
                    </div>
                    <div class="hb-admin-log-prompt-block">
                        <strong>stdout</strong>
                        <pre>${escapeHtml(output || 'No stdout returned.')}</pre>
                    </div>
                    ${error ? `<div class="hb-admin-log-prompt-block"><strong>stderr</strong><pre>${escapeHtml(error)}</pre></div>` : ''}
                    <div class="hb-modal-actions">
                        <button class="hb-button-secondary" type="button" data-close-modal ${running ? 'disabled' : ''}>Close</button>
                    </div>
                </section>
            </div>`;
    }

    function issueReportModalMarkup() {
        return `
            <div class="hb-modal-backdrop" role="dialog" aria-modal="true" aria-label="Report an issue">
                <form class="hb-card hb-modal hb-form hb-issue-report-modal" data-modal-form="issue-report">
                    ${sectionTitle(icons.activity, 'Report an issue', 'Tell us what happened so we can fix it quickly.')}
                    ${errorMarkup(state.error)}
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

    function issueReportSuccessModalMarkup() {
        return `
            <div class="hb-modal-backdrop" role="dialog" aria-modal="true" aria-labelledby="issue-report-success-title">
                <section class="hb-card hb-modal hb-issue-report-success-modal">
                    <div class="hb-issue-report-success-icon" aria-hidden="true">${icons.checkCircle}</div>
                    <h2 id="issue-report-success-title">Thank you for helping improve HeyBean!</h2>
                    <p>We've received your feedback and will fix any issues ASAP!</p>
                    <div class="hb-modal-actions hb-issue-report-success-actions">
                        <button class="hb-button" type="button" data-close-modal>Done</button>
                    </div>
                </section>
            </div>`;
    }

    function itemModalMarkup(kind, item = null, parentTask = null) {
        const editing = Boolean(item);
        const isReminder = kind === 'reminder';
        const isEvent = kind === 'event';
        const isTask = kind === 'task';
        const eventStart = isEvent ? (item?.starts_at || item?.startsAt || defaultEventStart()) : null;
        const eventEnd = isEvent ? (item?.ends_at || item?.endsAt || defaultEventEnd(eventStart)) : null;
        const when = isEvent
            ? toDatetimeLocal(eventStart)
            : item ? toDatetimeLocal(item.due_at || item.dueAt || item.remind_at) : '';
        const end = isEvent ? toDatetimeLocal(eventEnd) : '';
        const workspaceId = item?.workspace_id || item?.workspaceId || currentWorkspaceId();
        const title = parentTask ? 'New sub-task' : `${editing ? 'Edit' : 'New'} ${kind}`;
        const subtitle = parentTask
            ? `Assigned to ${parentTask.title || parentTask.name || 'task'}`
            : isEvent
                ? 'Schedule, details, and calendar sync'
                : isReminder
                    ? 'Time-sensitive nudge with optional repeat'
                    : 'Keep the task lightweight, dated, and organized';
        return `
            <div class="hb-modal-backdrop" role="dialog" aria-modal="true">
                <form class="hb-card hb-modal hb-form hb-item-form hb-item-form-${kind}" data-modal-form="${kind}">
                    ${parentTask ? `<input type="hidden" name="parentTaskId" value="${escapeAttr(parentTask.id)}">` : ''}
                    ${sectionTitle(isEvent ? icons.calendar : isReminder ? icons.reminders : icons.tasks, title, subtitle)}
                    <div class="hb-form-section hb-form-section-primary">
                        ${labelInput(`${capitalize(kind)} title`, 'title', 'text', item?.title || item?.name || '', 'required')}
                        ${isEvent ? eventTimeFieldsMarkup(item, when, end) : labelInput(isReminder ? 'Remind me at' : 'Due date', 'time', 'datetime-local', when, isReminder ? 'required' : '')}
                    </div>
                    ${isTask ? formSectionMarkup('Details', 'Notes and importance', `
                        <label class="hb-label">Notes<textarea class="hb-textarea" name="notes" placeholder="Add task details">${escapeHtml(item?.notes || '')}</textarea></label>
                        ${criticalToggleMarkup(item)}
                        ${editing && !taskParentId(item) ? `<button class="hb-button-ghost hb-inline-action" type="button" data-create-subtask="${item.id}">Add sub-task</button>` : ''}
                    `) : ''}
                    ${isEvent ? formSectionMarkup('Event details', 'Location, description, and status', eventDetailFieldsMarkup(item)) : ''}
                    ${formSectionMarkup('Organize', 'Category, color, and workspace', `
                        <div class="hb-field-row hb-compact-field-row">
                            ${categorySelectMarkup(item)}
                            ${labelInput('Color', 'color', 'color', itemColor(item))}
                        </div>
                        ${categoryManagerToggleMarkup()}
                        ${!isReminder && !isTask ? criticalToggleMarkup(item) : ''}
                    `)}
                    ${workspaceConnectionsMarkup(kind, item, workspaceId, editing)}
                    ${formSectionMarkup('Repeat', 'Make this repeat when it should come back', recurrenceFieldsMarkup(kind, item))}
                    <div class="hb-modal-actions">
                        ${editing ? `<button class="hb-button-danger" type="button" data-modal-delete="${kind}" data-id="${item.id}">Delete</button>` : ''}
                        <button class="hb-button-secondary" type="button" data-close-modal>Cancel</button>
                        <button class="hb-button" type="submit" data-modal-save-button>${editing ? 'Save' : 'Create'}</button>
                    </div>
                </form>
            </div>`;
    }

    function formSectionMarkup(title, subtitle, content) {
        return `
            <section class="hb-form-section">
                <div class="hb-form-section-head">
                    <strong>${escapeHtml(title)}</strong>
                    ${subtitle ? `<span>${escapeHtml(subtitle)}</span>` : ''}
                </div>
                <div class="hb-form-section-body">${content}</div>
            </section>`;
    }

    function criticalToggleMarkup(item = null) {
        return `
            <label class="hb-switch-row hb-form-switch">
                <input type="checkbox" name="critical" ${item?.is_critical || item?.isCritical ? 'checked' : ''}>
                <span><strong>Critical</strong><small>Keep this visible in today’s priority view.</small></span>
            </label>`;
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
            <div class="hb-field-row">
                ${labelInput('Location', 'location', 'text', item?.location || '')}
                <label class="hb-label">Status<select class="hb-select" name="status">
                    ${['confirmed', 'tentative', 'cancelled'].map((status) => `<option value="${status}" ${String(item?.status || 'confirmed') === status ? 'selected' : ''}>${capitalize(status)}</option>`).join('')}
                </select></label>
            </div>
            <label class="hb-label">Description<textarea class="hb-textarea" name="description" placeholder="Add notes, agenda, links, or anything useful for this event">${escapeHtml(item?.description || '')}</textarea></label>`;
    }

    function eventTimeFieldsMarkup(item = null, when = '', end = '') {
        const allDay = eventAllDay(item);
        const startSource = item?.starts_at || item?.startsAt || when || defaultEventStart();
        const startDate = allDay ? storedDateOnly(startSource) : dateOnly(startSource);
        const endDate = allDayEndDateInputValue(item, startDate);
        return `
            <label class="hb-switch-row hb-form-switch hb-all-day-toggle"><input type="checkbox" name="allDay" data-all-day-toggle ${allDay ? 'checked' : ''}> <span><strong>All day</strong><small>Use dates instead of specific start and end times.</small></span></label>
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
                <button class="hb-button-ghost hb-inline-action" type="button" data-open-categories aria-expanded="false">Manage categories</button>
                <div class="hb-inline-category-manager" data-category-manager hidden>
                    <div class="hb-inline-category-head">
                        <strong>Categories</strong>
                        <span data-inline-category-message></span>
                    </div>
                    <div class="hb-inline-category-create">
                        <label class="hb-label">New category<input class="hb-input" type="text" data-inline-category-name placeholder="Category name"></label>
                        <label class="hb-label">Color<input class="hb-input hb-color-input" type="color" data-inline-category-color value="${escapeAttr(themeAccentColor())}"></label>
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
            <section class="hb-form-section hb-event-connections hb-workspace-picker" data-workspace-picker>
                <div class="hb-form-section-head">
                    <strong>${title}</strong>
                    <span>${kind === 'event' ? 'Workspace sync and Google Calendar export' : 'Choose where this item belongs'}</span>
                </div>
                <div class="hb-form-section-body">
                <label class="hb-label">Primary workspace
                    <select class="hb-select" name="workspaceId" data-primary-workspace-select ${editing ? 'disabled' : ''}>
                        ${allWorkspaces.map((workspace) => `<option value="${escapeAttr(workspace.id)}" ${String(workspace.id) === sourceWorkspaceId ? 'selected' : ''}>${escapeHtml(workspace.name || 'Workspace')}</option>`).join('')}
                    </select>
                </label>
                ${editing ? `<input type="hidden" name="workspaceId" value="${escapeAttr(sourceWorkspaceId)}"><p class="hb-item-meta">Saved in ${escapeHtml(sourceWorkspace?.name || 'this workspace')}.</p>` : ''}
                <div data-sync-workspace-options>${workspaceSyncOptionsMarkup(sourceWorkspaceId, linked)}</div>
                ${kind === 'event' ? `<div data-google-export-options>${googleEventConnectionMarkup(item, sourceWorkspace)}</div>` : ''}
                </div>
            </section>`;
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
                        <p class="hb-item-meta">Bean uses OpenAI audio automatically.</p>
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
            <label class="hb-label">Recurrence
                <select class="hb-select" name="recurrence" data-recurrence-select>
                    ${recurrenceOptions().map((value) => `<option value="${value}" ${value === recurrence ? 'selected' : ''}>${recurrenceLabel(value)}</option>`).join('')}
                </select>
            </label>
            <div class="hb-tabs hb-recurrence-days" data-recurrence-days ${recurrence === 'specific_days' ? '' : 'hidden'}>
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
                        <div class="hb-field-row">${labelInput('Name', 'name', 'text', '', 'required')}${labelInput('Color', 'color', 'color', themeAccentColor())}</div>
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

    function bindSubscriptionActions() {
        mount.querySelectorAll('[data-subscribe-plan]').forEach((button) => button.addEventListener('click', () => startSubscriptionCheckout(button.dataset.subscribePlan)));
        mount.querySelectorAll('[data-subscribe-dashboard]').forEach((button) => button.addEventListener('click', () => {
            history.pushState({}, '', '/app');
            state.selected = 'today';
            loadSignedIn();
        }));
        mount.querySelectorAll('[data-subscribe-refresh]').forEach((button) => button.addEventListener('click', refreshSubscriptionStatus));
        mount.querySelectorAll('[data-subscribe-logout]').forEach((button) => button.addEventListener('click', logout));
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
                ? { name: data.name, email: data.email, password: data.password, password_confirmation: data.password_confirmation, ...(data.plan ? { plan: data.plan } : {}) }
                : { email: data.email, password: data.password };
            const result = await api(`/auth/${action}`, { method: 'POST', body: payload });
            if (action === 'register') {
                state.busy = false;
                state.subscriptionCheckoutStatus = '';
                state.user = result.user || null;
                state.subscriptionSummary = null;
                state.modal = { type: 'register-early-access-success' };
                render();
                return;
            }
            persistToken(result.token, action === 'login' && data.remember === 'on');
            state.busy = false;
            history.pushState({}, '', initialSelectedView() === 'admin' ? '/admin' : '/app');
            await loadSignedIn();
        } catch (error) {
            state.busy = false;
            state.error = friendlyError(error, action === 'register' ? 'sign up for early access' : action === 'forgot' ? 'send a password reset link' : 'sign in');
            render();
        }
    }

    async function startSubscriptionCheckout(plan) {
        if (!subscriptionPlans[plan] || state.busy) return;
        state.busy = true;
        state.error = '';
        state.selectedPlan = plan;
        render();
        try {
            const checkout = await api('/billing/checkout-sessions', {
                method: 'POST',
                body: { plan, source: 'subscribe' },
            });
            if (!checkout?.url) throw new Error('Stripe did not return a payment page.');
            window.location.href = checkout.url;
        } catch (error) {
            state.busy = false;
            state.error = friendlyError(error, 'start your subscription');
            render();
        }
    }

    async function refreshSubscriptionStatus() {
        if (state.busy) return;
        state.busy = true;
        state.error = '';
        render();
        try {
            const [user, subscription] = await Promise.all([
                api('/auth/me'),
                api('/billing/subscription'),
            ]);
            state.user = user;
            state.subscriptionSummary = subscription;
            state.busy = false;
            state.error = '';
        } catch (error) {
            state.busy = false;
            state.error = friendlyError(error, 'refresh your subscription status');
        }
        render();
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
        mount.querySelectorAll('[data-toggle-kiosk-voice]').forEach((button) => button.addEventListener('click', toggleKioskVoiceMode));
        mount.querySelectorAll('[data-mobile-bean-button]').forEach((button) => {
            button.addEventListener('pointerdown', handleMobileBeanPointerDown);
            button.addEventListener('click', handleMobileBeanClick);
            button.addEventListener('contextmenu', handleMobileBeanContextMenu);
        });
        mount.querySelector('[data-onboarding-dashboard]')?.addEventListener('click', () => {
            state.selected = 'today';
            state.chatExpanded = false;
            state.onboardingJustCompleted = false;
            state.error = '';
            state.notice = '';
            render();
        });
        mount.querySelector('[data-onboarding-tour-next]')?.addEventListener('click', () => {
            state.onboardingTourStep = Math.min(state.onboardingTourStep + 1, onboardingTourSteps.length - 1);
            render();
        });
        mount.querySelector('[data-onboarding-tour-skip]')?.addEventListener('click', () => {
            closeOnboardingTour();
            render();
        });
        mount.querySelector('[data-onboarding-tour-finish]')?.addEventListener('click', () => {
            closeOnboardingTour();
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
            const selected = allowedCalendarDate(button.dataset.selectDay);
            if (selected.blocked) showCalendarHistoryLimit();
            state.selectedDay = dateOnly(selected.date);
            resetCalendarWindow(selected.date);
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
        mount.querySelector('[data-admin-settings-form]')?.addEventListener('submit', saveAdminSettings);
        mount.querySelector('[data-admin-plan-limits-form]')?.addEventListener('submit', saveAdminPlanLimits);
        mount.querySelectorAll('[data-enterprise-limit-form]').forEach((form) => form.addEventListener('submit', saveEnterpriseLimits));
        mount.querySelectorAll('[data-enterprise-limit-delete]').forEach((button) => button.addEventListener('click', () => deleteEnterpriseLimits(button.dataset.enterpriseLimitDelete)));
        mount.querySelector('[data-update-hermes]')?.addEventListener('click', updateHermesRuntime);
        mount.querySelectorAll('[data-user-growth-range]').forEach((button) => button.addEventListener('click', () => setAdminUserGrowthRange(button.dataset.userGrowthRange)));
        mount.querySelector('[data-toggle-archived-issues]')?.addEventListener('click', () => { state.adminArchivedIssuesOpen = !state.adminArchivedIssuesOpen; render(); });
        mount.querySelectorAll('[data-issue-status]').forEach((button) => button.addEventListener('click', () => updateIssueReportStatus(button.dataset.issueStatus, button.dataset.status)));
        mount.querySelectorAll('[data-admin-log-id]').forEach((button) => button.addEventListener('click', () => openAdminUsageLog(button.dataset.adminLogId)));
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
        mount.querySelectorAll('[data-toggle-task-future]').forEach((button) => button.addEventListener('click', (event) => {
            event.preventDefault();
            event.stopPropagation();
            toggleFutureTaskBucket(button.dataset.toggleTaskFuture);
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
        mount.querySelector('[data-theme-select]')?.addEventListener('change', updateThemePreference);
        mount.querySelector('[data-home-city-form]')?.addEventListener('submit', updateHomeCityPreference);
        mount.querySelector('[data-clear-home-city]')?.addEventListener('click', clearHomeCityPreference);
        mount.querySelector('[data-billing-change-plan]')?.addEventListener('click', changeBillingPlan);
        mount.querySelector('[data-billing-update-payment]')?.addEventListener('click', startBillingPaymentUpdate);
        mount.querySelector('[data-billing-refresh]')?.addEventListener('click', () => refreshBillingSettings({ user: true }));
        mount.querySelector('[data-billing-cancel-renewal]')?.addEventListener('click', cancelBillingRenewal);
        mount.querySelector('[data-billing-resume-subscription]')?.addEventListener('click', resumeBillingSubscription);
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

    function toggleFutureTaskBucket(bucket) {
        if (!bucket || !Object.prototype.hasOwnProperty.call(state.futureTaskBucketsOpen, bucket)) return;
        state.futureTaskBucketsOpen[bucket] = !state.futureTaskBucketsOpen[bucket];
        render();
    }

    function bindModalActions() {
        mount.querySelector('[data-register-early-access-home]')?.addEventListener('click', () => {
            window.location.href = '/';
        });
        mount.querySelectorAll('[data-close-modal]').forEach((button) => button.addEventListener('click', () => {
            state.modal = null;
            render();
        }));
        mount.querySelectorAll('[data-modal-delete]').forEach((button) => button.addEventListener('click', deleteModalItem));
        mount.querySelectorAll('[data-recurring-delete-mode]').forEach((button) => button.addEventListener('click', confirmRecurringDelete));
        mount.querySelector('[data-modal-form]')?.addEventListener('submit', submitModal);
        mount.querySelector('[data-open-categories]')?.addEventListener('click', toggleInlineCategoryManager);
        mount.querySelector('[data-open-settings-categories]')?.addEventListener('click', () => { state.modal = { type: 'categories' }; render(); });
        mount.querySelector('[data-settings-category-select]')?.addEventListener('change', (event) => {
            state.settingsCategoryId = event.currentTarget.value;
            render();
        });
        mount.querySelector('[data-settings-category-form]')?.addEventListener('submit', saveSettingsCategory);
        mount.querySelector('[data-settings-category-delete]')?.addEventListener('click', (event) => deleteSettingsCategory(event.currentTarget.dataset.settingsCategoryDelete));
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
                if (state.modal?.type === 'register-early-access-success') {
                    window.location.href = '/';
                    return;
                }
                if (state.modal?.type === 'admin-command-run' && adminCommandRunActive(state.modal?.status)) return;
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
                throw new Error(beanVoiceStatusMessage(payload?.message || 'Bean preview failed.'));
            }
            const audioBuffer = await response.arrayBuffer();
            const played = await playOpenAiAudioBuffer(audioBuffer) || await playAudioBlobFallback(audioBuffer, response.headers.get('Content-Type') || 'audio/wav');
            if (!played) throw new Error('Bean needs one click.');
            setTtsPreviewStatus(status, `${capitalize(voice)} preview played.`, 'success');
        } catch (error) {
            setTtsPreviewStatus(status, beanVoiceStatusMessage(error?.message || 'Bean preview failed.'), 'error');
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
        const color = safeColor(colorInput?.value || themeAccentColor());
        if (!panel || !name) {
            setInlineCategoryMessage(panel, 'Add a category name.', 'error');
            return;
        }
        await withInlineCategoryBusy(button, async () => {
            const category = await api('/event-categories', { method: 'POST', body: { name, color } });
            cacheCategory(category);
            nameInput.value = '';
            colorInput.value = themeAccentColor();
            refreshInlineCategoryControls(panel, name, color);
            setInlineCategoryMessage(panel, 'Added.', '');
        });
    }

    async function saveInlineCategory(event) {
        const button = event.currentTarget;
        const row = button.closest('[data-inline-category-row]');
        const panel = button.closest('[data-category-manager]');
        const name = String(row?.querySelector('[data-inline-category-row-name]')?.value || '').trim();
        const color = safeColor(row?.querySelector('[data-inline-category-row-color]')?.value || themeAccentColor());
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
                await api('/event-categories', { method: 'POST', body: { name: data.name, color: data.color || themeAccentColor() } });
                await refreshOnly(false);
                state.modal = { type: 'categories' };
                render();
                return;
            } else {
                if (form.dataset.saving === 'true') return;
                form.dataset.saving = 'true';
                const item = state.modal?.item || null;
                const request = itemSaveRequest(kind, item, data, form);
                const mutationId = optimisticMutationId(kind);
                const optimistic = optimisticItemFromSaveRequest(kind, item, request, mutationId);
                const previousItem = item ? { ...item } : null;
                cacheSavedItem(kind, optimistic);
                state.modal = null;
                state.notice = 'Saved.';
                state.error = '';
                render();
                saveItemRequestInBackground(kind, request, optimistic, previousItem, mutationId);
                return;
            }
            state.modal = null;
            state.notice = 'Saved.';
            render();
            if (kind === 'event') {
                refreshCalendarAfterEventSave();
                return;
            }
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
            state.modal = { type: 'issue-report-success' };
            state.notice = '';
            render();
        } catch (error) {
            state.issueReportSubmitting = false;
            state.error = friendlyError(error, 'send that issue report');
            render();
        }
    }

    async function updateIssueReportStatus(id, status) {
        if (!id || !status || state.adminUsageLoading) return;
        state.adminUsageLoading = true;
        state.error = '';
        render();
        try {
            const updated = await api(`/admin/issue-reports/${encodeURIComponent(id)}`, {
                method: 'PATCH',
                body: { status },
            });
            updateAdminIssueReportLocal(updated);
            state.adminUsageLoading = false;
            render();
        } catch (error) {
            state.error = friendlyError(error, 'update that issue report');
            state.adminUsageLoading = false;
            render();
        }
    }

    function updateAdminIssueReportLocal(report) {
        if (!state.adminUsage || !report?.id) return;
        const openReports = normalizeList(state.adminUsage.issue_reports || state.adminUsage.issueReports);
        const archivedReports = normalizeList(state.adminUsage.archived_issue_reports || state.adminUsage.archivedIssueReports);
        const wasOpen = openReports.some((item) => String(item.id || '') === String(report.id));
        const wasArchived = archivedReports.some((item) => String(item.id || '') === String(report.id));
        const withoutReport = (items) => items.filter((item) => String(item.id || '') !== String(report.id));
        const openNext = withoutReport(openReports);
        const archivedNext = withoutReport(archivedReports);
        const status = String(report.status || 'open').toLowerCase();
        const totals = state.adminUsage.totals || {};
        let openCount = Number(totals.open_issue_reports ?? totals.openIssueReports ?? openReports.length);
        let archivedCount = Number(totals.archived_issue_reports ?? totals.archivedIssueReports ?? archivedReports.length);

        if (status === 'closed') {
            archivedNext.unshift(report);
            openCount = Math.max(0, openCount - (wasOpen ? 1 : 0));
            archivedCount += wasArchived ? 0 : 1;
        } else {
            openNext.unshift(report);
            openCount += wasOpen ? 0 : 1;
            archivedCount = Math.max(0, archivedCount - (wasArchived ? 1 : 0));
        }

        state.adminUsage = {
            ...state.adminUsage,
            totals: {
                ...totals,
                open_issue_reports: openCount,
                archived_issue_reports: archivedCount,
            },
            issue_reports: openNext,
            archived_issue_reports: archivedNext,
        };
    }

    function openAdminUsageLog(id) {
        const log = normalizeList(state.adminUsage?.recent_logs || state.adminUsage?.recentLogs)
            .find((item) => String(item.id || '') === String(id || ''));
        if (!log) return;
        state.modal = { type: 'admin-usage-log', log };
        render();
    }

    async function saveAdminSettings(event) {
        event.preventDefault();
        const form = event.currentTarget;
        const value = (name) => String(new FormData(form).get(name) || '').trim();
        const floatValue = (name) => Number.parseFloat(value(name));
        state.adminUsageLoading = true;
        state.error = '';
        render();
        try {
            state.adminUsage = {
                ...(state.adminUsage || {}),
                settings: await api('/admin/settings', {
                    method: 'PATCH',
                    body: {
                        model_settings: {
                            main_model: value('main_model'),
                            quick_voice_model: value('quick_voice_model'),
                            realtime_model: value('realtime_model'),
                            external_lookup_model: value('external_lookup_model'),
                        },
                        kill_switches: {
                            bean_chat_enabled: Boolean(form.querySelector('input[name="bean_chat_enabled"]')?.checked),
                            bean_voice_enabled: Boolean(form.querySelector('input[name="bean_voice_enabled"]')?.checked),
                        },
                        apply_main_model_to_profiles: Boolean(form.querySelector('input[name="apply_main_model_to_profiles"]')?.checked),
                    },
                }),
            };
            state.notice = 'Admin settings saved.';
            state.adminUsageLoading = false;
            render();
        } catch (error) {
            state.error = friendlyError(error, 'save admin settings');
            state.adminUsageLoading = false;
            render();
        }
    }

    async function saveAdminPlanLimits(event) {
        event.preventDefault();
        const form = event.currentTarget;
        const plans = {};
        form.querySelectorAll('[data-plan-limit-card]').forEach((card) => {
            plans[card.dataset.planLimitCard] = readAdminLimits(card);
        });
        state.adminUsageLoading = true;
        state.error = '';
        render();
        try {
            state.adminPlanLimits = await api('/admin/plan-limits/plans', {
                method: 'PATCH',
                body: { plans },
            });
            state.notice = 'Plan limits saved.';
        } catch (error) {
            state.error = friendlyError(error, 'save plan limits');
        } finally {
            state.adminUsageLoading = false;
            render();
        }
    }

    async function saveEnterpriseLimits(event) {
        event.preventDefault();
        const form = event.currentTarget;
        const id = form.dataset.enterpriseLimitForm || '';
        const formData = new FormData(form);
        const body = {
            user_id: nullableNumber(formData.get('user_id')),
            billing_type: String(formData.get('billing_type') || 'monthly'),
            monthly_rate_usd: nullableNumber(formData.get('monthly_rate_usd')),
            usage_rate_usd: nullableNumber(formData.get('usage_rate_usd')),
            limits: readAdminLimits(form),
            notes: String(formData.get('notes') || '').trim() || null,
        };
        state.adminUsageLoading = true;
        state.error = '';
        render();
        try {
            state.adminPlanLimits = await api(id ? `/admin/plan-limits/enterprise-customers/${encodeURIComponent(id)}` : '/admin/plan-limits/enterprise-customers', {
                method: id ? 'PATCH' : 'POST',
                body,
            });
            state.notice = id ? 'Enterprise limits saved.' : 'Enterprise customer added.';
        } catch (error) {
            state.error = friendlyError(error, 'save enterprise limits');
        } finally {
            state.adminUsageLoading = false;
            render();
        }
    }

    async function deleteEnterpriseLimits(id) {
        if (!id || !window.confirm('Remove this enterprise customer override?')) return;
        state.adminUsageLoading = true;
        state.error = '';
        render();
        try {
            await api(`/admin/plan-limits/enterprise-customers/${encodeURIComponent(id)}`, { method: 'DELETE' });
            state.adminPlanLimits = await api('/admin/plan-limits');
            state.notice = 'Enterprise override removed.';
        } catch (error) {
            state.error = friendlyError(error, 'remove enterprise limits');
        } finally {
            state.adminUsageLoading = false;
            render();
        }
    }

    function readAdminLimits(container) {
        const checked = (name) => Boolean(container.querySelector(`input[name="${name}"]`)?.checked);
        return {
            workspace_limit: nullableNumber(container.querySelector('input[name="workspace_limit"]')?.value),
            calendar_connection_limit: nullableNumber(container.querySelector('input[name="calendar_connection_limit"]')?.value),
            connected_account_limit: nullableNumber(container.querySelector('input[name="connected_account_limit"]')?.value),
            history_days: nullableNumber(container.querySelector('input[name="history_days"]')?.value),
            daily_cost_limit: nullableNumber(container.querySelector('input[name="daily_cost_limit"]')?.value),
            daily_external_cost_limit: nullableNumber(container.querySelector('input[name="daily_external_cost_limit"]')?.value),
            recurring_tasks_enabled: checked('recurring_tasks_enabled'),
            recurring_reminders_enabled: checked('recurring_reminders_enabled'),
            recurring_calendar_enabled: checked('recurring_calendar_enabled'),
            email_reminders_enabled: checked('email_reminders_enabled'),
            priority_background_work: checked('priority_background_work'),
        };
    }

    function nullableNumber(value) {
        const normalized = String(value ?? '').trim();
        if (!normalized) return null;
        const parsed = Number.parseFloat(normalized);
        return Number.isFinite(parsed) ? parsed : null;
    }

    async function updateHermesRuntime() {
        if (state.adminHermesUpdating) return;
        state.adminHermesUpdating = true;
        state.error = '';
        state.notice = '';
        state.modal = {
            type: 'admin-command-run',
            status: 'queued',
        };
        render();
        try {
            const run = await api('/admin/hermes/update', { method: 'POST' });
            state.modal = {
                type: 'admin-command-run',
                status: run.status,
                runId: run.id,
                result: run,
            };
            pollAdminCommandRun(run.id);
        } catch (error) {
            const result = error.payload?.data || null;
            state.modal = {
                type: 'admin-command-run',
                status: 'failed',
                result,
                error: friendlyError(error, 'update Hermes'),
            };
            state.adminHermesUpdating = false;
            render();
        }
    }

    function pollAdminCommandRun(runId) {
        if (!runId) {
            state.adminHermesUpdating = false;
            render();
            return;
        }
        window.clearTimeout(adminCommandRunPollTimer);
        api(`/admin/command-runs/${encodeURIComponent(runId)}`)
            .then((run) => {
                state.modal = {
                    type: 'admin-command-run',
                    status: run.status,
                    runId: run.id,
                    result: run,
                };
                if (adminCommandRunActive(run.status)) {
                    adminCommandRunPollTimer = window.setTimeout(() => pollAdminCommandRun(runId), 1000);
                } else {
                    state.adminHermesUpdating = false;
                    state.notice = run.status === 'completed' ? 'Hermes update completed.' : 'Hermes update failed.';
                    api('/admin/hermes/status').then((status) => {
                        state.adminHermesStatus = status;
                        render();
                    }).catch(() => render());
                    return;
                }
                render();
            })
            .catch((error) => {
                state.adminHermesUpdating = false;
                state.error = friendlyError(error, 'load command output');
                render();
            });
    }

    function adminCommandRunActive(status) {
        return ['queued', 'running'].includes(String(status || '').toLowerCase());
    }

    function setAdminUserGrowthRange(range) {
        if (!['today', 'last_7_days', 'last_30_days', 'all_time'].includes(range) || state.adminUserGrowthRange === range) return;
        state.adminUserGrowthRange = range;
        loadAdminUsage(true);
    }

    function itemSaveRequest(kind, item, data, form) {
        const color = data.color || themeAccentColor();
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
            return {
                body,
                path: item ? `/tasks/${item.id}` : '/tasks',
                options: { method: item ? 'PATCH' : 'POST', body },
            };
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
            return {
                body,
                path: item ? `/reminders/${item.id}` : '/reminders',
                options: { method: item ? 'PATCH' : 'POST', body },
            };
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
            return {
                body,
                path: item ? `/calendar-events/${item.id}` : '/calendar-events',
                options: { method: item ? 'PATCH' : 'POST', body },
            };
        }
        return { body: {}, path: '', options: {} };
    }

    async function saveItemRequest(kind, request) {
        const saved = await api(request.path, request.options);
        return normalizeSavedItem(kind, saved, request);
    }

    function saveItemRequestInBackground(kind, request, optimistic, previousItem, mutationId) {
        saveItemRequest(kind, request)
            .then((saved) => {
                reconcileSavedOptimisticItem(kind, optimistic, saved, mutationId);
                if (kind === 'event') {
                    refreshCalendarAfterEventSave();
                    return;
                }
                refreshOnlyInBackground({ skipCalendarSync: true });
            })
            .catch((error) => {
                rollbackOptimisticSave(kind, optimistic, previousItem, mutationId);
                state.error = friendlyError(error, `save that ${kind}`);
                state.notice = '';
                render();
            });
    }

    function normalizeSavedItem(kind, saved, request = {}) {
        if (!saved || kind !== 'event') return saved;
        const linked = normalizeList(saved.linked_workspace_ids || saved.linkedWorkspaceIds);
        return {
            ...saved,
            linked_workspace_ids: linked.length ? linked : optimisticLinkedWorkspaceIds(saved, request.body || {}),
        };
    }

    function optimisticMutationId(kind) {
        return `${kind}-${Date.now()}-${Math.random().toString(36).slice(2)}`;
    }

    function nextLocalResourceId() {
        const id = localResourceSequence;
        localResourceSequence -= 1;
        return id;
    }

    function optimisticItemFromSaveRequest(kind, item, request, mutationId) {
        const body = request.body || request.options?.body || {};
        const id = item?.id ?? nextLocalResourceId();
        const workspaceId = body.workspace_id || item?.workspace_id || item?.workspaceId || currentWorkspaceId() || null;
        const linkedWorkspaceIds = optimisticLinkedWorkspaceIds(item, body);
        const base = {
            ...(item || {}),
            id,
            workspace_id: workspaceId,
            workspaceId,
            linked_workspace_ids: linkedWorkspaceIds,
            linkedWorkspaceIds: linkedWorkspaceIds,
            metadata: body.metadata || item?.metadata || {},
            category: body.category || null,
            color: body.color || themeAccentColor(),
            __optimisticMutationId: mutationId,
        };
        if (kind === 'task') {
            return {
                ...base,
                title: body.title,
                name: body.title,
                type: body.type || item?.type || 'todo',
                status: body.status || item?.status || 'open',
                due_at: body.due_at,
                dueAt: body.due_at,
                notes: body.notes,
                is_critical: body.is_critical === true,
                isCritical: body.is_critical === true,
            };
        }
        if (kind === 'reminder') {
            return {
                ...base,
                title: body.title,
                name: body.title,
                status: body.status || item?.status || 'pending',
                remind_at: body.remind_at,
                remindAt: body.remind_at,
                due_at: body.remind_at,
                dueAt: body.remind_at,
            };
        }
        if (kind === 'event') {
            return {
                ...base,
                title: body.title,
                description: body.description,
                location: body.location,
                starts_at: body.starts_at,
                startsAt: body.starts_at,
                ends_at: body.ends_at,
                endsAt: body.ends_at,
                recurrence: body.recurrence,
                status: body.status || item?.status || 'confirmed',
                is_critical: body.is_critical === true,
                isCritical: body.is_critical === true,
                all_day: body.metadata?.all_day === true,
                allDay: body.metadata?.all_day === true,
            };
        }
        return base;
    }

    function optimisticLinkedWorkspaceIds(item = null, body = {}) {
        const existing = normalizeList(item?.linked_workspace_ids || item?.linkedWorkspaceIds);
        const ids = [
            body.workspace_id || item?.workspace_id || item?.workspaceId || currentWorkspaceId(),
            ...normalizeList(body.sync_to_workspace_ids),
            ...existing,
        ].filter(Boolean).map(String);
        return Array.from(new Set(ids));
    }

    function reconcileSavedOptimisticItem(kind, optimistic, saved, mutationId) {
        if (!saved?.id || !optimisticSaveStillCurrent(kind, optimistic, mutationId)) return;
        const optimisticId = String(optimistic?.id || '');
        const savedId = String(saved.id);
        if (optimisticId && optimisticId !== savedId) removeLocalItem(kind, optimisticId);
        cacheSavedItem(kind, saved);
        renderDashboardDataUpdate({ deferIfEditing: true });
    }

    function optimisticSaveStillCurrent(kind, optimistic, mutationId) {
        const current = findById(listForKind(kind), optimistic?.id);
        return Boolean(current && current.__optimisticMutationId === mutationId);
    }

    function rollbackOptimisticSave(kind, optimistic, previousItem, mutationId) {
        const current = findById(listForKind(kind), optimistic?.id);
        if (!current || current.__optimisticMutationId !== mutationId) return;
        removeLocalItem(kind, optimistic?.id);
        if (previousItem) setListForKind(kind, upsertById(listForKind(kind), previousItem));
        clearPendingItem(kind, optimistic?.id);
        if (previousItem?.id) clearPendingItem(kind, previousItem.id);
        saveDashboardCache();
    }

    function listForKind(kind) {
        if (kind === 'task') return state.tasks;
        if (kind === 'reminder') return state.reminders;
        if (kind === 'event') return state.calendar;
        return [];
    }

    function setListForKind(kind, list) {
        if (kind === 'task') state.tasks = list;
        if (kind === 'reminder') state.reminders = list;
        if (kind === 'event') state.calendar = list;
    }

    function clearPendingItem(kind, id) {
        const key = String(id || '');
        if (!key) return;
        if (kind === 'task') {
            state.pendingTaskUpserts.delete(key);
            state.pendingTaskDeletes.delete(key);
        }
        if (kind === 'reminder') {
            state.pendingReminderUpserts.delete(key);
            state.pendingReminderDeletes.delete(key);
        }
        if (kind === 'event') {
            state.pendingCalendarUpserts.delete(key);
            state.pendingCalendarDeletes.delete(key);
        }
    }

    function removeLocalItem(kind, id) {
        const key = String(id || '');
        if (!key) return;
        setListForKind(kind, listForKind(kind).filter((item) => String(item.id) !== key));
        clearPendingItem(kind, key);
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
        const styles = getComputedStyle(textarea);
        const minHeight = Number.parseFloat(styles.minHeight) || 44;
        const maxHeight = Number.parseFloat(styles.maxHeight) || Math.ceil(((Number.parseFloat(styles.lineHeight) || 20) * 2) + 28);
        textarea.style.height = 'auto';
        textarea.style.maxHeight = `${maxHeight}px`;
        textarea.style.height = `${Math.max(minHeight, Math.min(textarea.scrollHeight, maxHeight))}px`;
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

    function handleMobileBeanPointerDown(event) {
        if (typeof event.button === 'number' && event.button !== 0) return;
        mobileBeanPressing = true;
        mobileBeanHoldStarted = false;
        mobileBeanPointerId = event.pointerId;
        mobileBeanClickSuppressed = true;
        window.clearTimeout(mobileBeanHoldTimer);
        mobileBeanHoldTimer = window.setTimeout(beginMobileBeanVoiceHold, 520);
        window.addEventListener('pointerup', handleMobileBeanPointerEnd, true);
        window.addEventListener('pointercancel', handleMobileBeanPointerCancel, true);
        event.currentTarget.setPointerCapture?.(event.pointerId);
        event.preventDefault();
    }

    function handleMobileBeanPointerEnd(event) {
        if (mobileBeanPointerId !== null && event.pointerId !== mobileBeanPointerId) return;
        event.preventDefault();
        finishMobileBeanPress(true);
    }

    function handleMobileBeanPointerCancel(event) {
        if (mobileBeanPointerId !== null && event.pointerId !== mobileBeanPointerId) return;
        event.preventDefault();
        finishMobileBeanPress(false);
    }

    function finishMobileBeanPress(released) {
        window.clearTimeout(mobileBeanHoldTimer);
        mobileBeanHoldTimer = 0;
        window.removeEventListener('pointerup', handleMobileBeanPointerEnd, true);
        window.removeEventListener('pointercancel', handleMobileBeanPointerCancel, true);
        window.setTimeout(() => {
            mobileBeanClickSuppressed = false;
        }, 350);

        const wasHolding = mobileBeanHoldStarted;
        mobileBeanPressing = false;
        mobileBeanHoldStarted = false;
        mobileBeanPointerId = null;

        if (!released) {
            if (wasHolding || state.voiceListening) finishVoiceHoldInput(false);
            return;
        }

        if (!wasHolding) {
            openBeanTextChat();
            return;
        }

        voiceHoldPressed = false;
        if (voiceStartPending && !state.voiceListening) return;
        if (!voiceHoldActive && !state.voiceListening) return;
        sendVoiceDraftImmediately();
    }

    function beginMobileBeanVoiceHold() {
        mobileBeanHoldTimer = 0;
        if (!mobileBeanPressing || state.busy) return;
        mobileBeanHoldStarted = true;
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
    }

    function handleMobileBeanClick(event) {
        if (mobileBeanClickSuppressed) {
            event.preventDefault();
            event.stopPropagation();
            mobileBeanClickSuppressed = false;
            return;
        }
        openBeanTextChat();
    }

    function handleMobileBeanContextMenu(event) {
        event.preventDefault();
        event.stopPropagation();
    }

    function openBeanTextChat() {
        state.selected = 'bean';
        state.chatExpanded = false;
        state.error = '';
        state.notice = '';
        render();
        scrollChatToBottom();
    }

    async function submitChat(event) {
        event.preventDefault();
        const form = event.currentTarget;
        const content = new FormData(form).get('message')?.toString().trim();
        if (!content || state.busy) return;
        await sendChatContent(content);
    }

    async function cancelBeanTurn(event = null, options = {}) {
        event?.preventDefault?.();
        event?.stopPropagation?.();
        const preserveKioskStatus = options.preserveKioskStatus === true;
        if (activeChatRequestId) {
            cancelledChatRequestIds.add(activeChatRequestId);
        }
        stopBeanWorkEventPolling();
        state.busy = false;
        state.chatRunState = 'Ready';
        state.beanWorkItems = [];
        state.voiceStatus = '';
        state.voiceStatusTone = '';
        kioskConversationActive = false;
        kioskCommandText = '';
        kioskRealtimePendingUser = null;
        kioskRealtimeCurrentUserTurn = null;
        kioskRealtimeAssistantDraft = null;
        kioskRealtimeSuppressNextAssistantPersist = false;
        kioskRealtimeVoiceOnlyAssistant = false;
        kioskRealtimeIgnoreNextFunctionCalls = false;
        setRealtimeBackgroundWorkActive(false);
        kioskRealtimeAwaitingFollowup = false;
        kioskRealtimeLastAssistantText = '';
        kioskRealtimeLastAssistantOutputEndedAt = 0;
        kioskRealtimeWakeContinuationUntil = 0;
        kioskRealtimeResponseCreateSentAt = 0;
        kioskRealtimeAwaitingFirstAudio = false;
        kioskRealtimeSpokenSegments.length = 0;
        clearRealtimeAssistantOutputGuard();
        kioskRealtimePendingBackgroundResult = null;
        kioskRealtimePendingFunctionCalls = [];
        window.clearTimeout(kioskRealtimeBackgroundDeliveryTimer);
        kioskRealtimeBackgroundDeliveryTimer = 0;
        kioskRealtimeUserTranscriptDrafts.clear();
        window.clearTimeout(kioskRealtimeResponseTimer);
        kioskRealtimeResponseTimer = 0;
        clearRealtimeToolFallback();
        kioskRealtimeRunWatchTimers.forEach((timer) => window.clearTimeout(timer));
        kioskRealtimeRunWatchTimers.clear();
        clearDeferredRealtimeFunctionOutputs();
        if (
            kioskRealtime?.dataChannel?.readyState === 'open'
            && (kioskRealtimeAwaitingFirstAudio || realtimeAssistantOutputActive() || ['responding', 'speaking'].includes(state.kioskVoicePhase))
        ) {
            try { kioskRealtime.dataChannel.send(JSON.stringify({ type: 'response.cancel' })); } catch (_) {}
        }
        stopKioskSpeechPlayback();
        if (!preserveKioskStatus) {
            setKioskVoiceStatus(
                state.kioskVoiceEnabled ? (kioskRealtimeConnected() ? 'armed' : 'working') : 'idle',
                state.kioskVoiceEnabled ? (kioskRealtimeConnected() ? 'Say hey bean' : 'Bean is waking up') : ''
            );
        }
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
        resetBeanWorkItems(options.voiceQuickReply || options.voiceQuickReplyPending ? 'Follow up on voice request' : 'Read request');
        state.error = '';
        render();
        try {
            if (!state.session?.id) {
                const onboarding = needsBeanOnboarding();
                state.session = await api('/assistant/sessions', {
                    method: 'POST',
                    body: chatSessionPayload(onboarding),
                });
            }
            startBeanWorkEventPolling(state.session.id);
            const metadata = {
                client_context: clientContextPayload(),
                ...(options.voiceQuickReply || options.voiceQuickReplyPending
                    ? {
                        voice_context: {
                            mode: 'live_voice',
                            ...(options.voiceDetailedChat ? { detailed_chat: true } : {}),
                            ...(options.voiceQuickReplyMode ? { quick_reply_mode: options.voiceQuickReplyMode } : {}),
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
            applyBeanWorkEvents(result.events);
            if (result.user_message) replaceLocalUserMessage(result.user_message);
            if (result.assistant_message) {
                state.messages.push(result.assistant_message);
                assistantContent = result.assistant_message.content || '';
            }
            completeBeanWorkItem(state.beanWorkItems[0]?.id, 'Finish request');
            if (result.status === 'blocked' && isPlanLimitMessage(assistantContent)) {
                state.error = assistantContent;
            }
            state.chatRunState = result.status === 'blocked' ? 'Blocked' : 'Ready';
            await refreshOnly(false);
            if (wasOnboarding && !needsBeanOnboarding()) {
                state.onboardingJustCompleted = true;
                startOnboardingTourIfNeeded();
            }
            loadChatSessions({ resumeToday: false, shouldRender: false }).then(() => render()).catch(() => {});
        } catch (error) {
            if (!cancelledChatRequestIds.has(requestId)) {
                assistantContent = friendlyError(error, 'send that message');
                state.error = assistantContent;
                state.messages.push({ id: `error-${Date.now()}`, role: 'assistant', content: assistantContent });
                state.chatRunState = 'Failed';
            }
        } finally {
            cancelledChatRequestIds.delete(requestId);
            if (activeChatRequestId === requestId) {
                activeChatRequestId = 0;
                state.busy = false;
                stopBeanWorkEventPolling();
            }
            if (state.beanWorkItems.length && state.beanWorkItems.every((item) => beanWorkItemDone(item))) {
                scheduleBeanWorkStatusClear();
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
                upsertBeanWorkItem('voice-dictation', 'Send dictated request', 'running');
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
                state.beanWorkItems = [];
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
            state.beanWorkItems = [];
            state.voiceStatus = voiceErrorMessage(event.error);
            state.voiceStatusTone = 'error';
            render();
            restartKioskVoiceListeningSoon(900);
        };
        state.voiceRecognition = recognition;
        state.voiceListening = true;
        state.error = '';
        resetBeanWorkItems('Listening for your request');
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
        const realtime = kioskRealtime;
        if (!realtime?.connected || !realtime.peerConnection || realtime.dataChannel?.readyState !== 'open') return false;
        const connectionState = realtime.peerConnection.connectionState || '';
        const iceConnectionState = realtime.peerConnection.iceConnectionState || '';
        return !['failed', 'closed', 'disconnected'].includes(connectionState)
            && !['failed', 'closed', 'disconnected'].includes(iceConnectionState);
    }

    function recoverKioskRealtimeAfterSendFailure(reason = 'response_create_failed') {
        reportKioskRealtimeIssue(reason, {
            data_channel_state: kioskRealtime?.dataChannel?.readyState || '',
            connection_state: kioskRealtime?.peerConnection?.connectionState || '',
            ice_connection_state: kioskRealtime?.peerConnection?.iceConnectionState || '',
        });
        stopKioskRealtimeVoiceMode({ preserveStatus: true, preserveReconnect: true });
        setKioskVoiceStatus('working', 'Bean is waking up');
        scheduleKioskRealtimeReconnect(reason, {}, 250);
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

    function logKioskRealtimeVoiceTrace(type, details = {}) {
        if (!state.token) return;
        const payload = {
            event_type: String(type || 'realtime_voice_trace').slice(0, 100),
            session_id: kioskRealtime?.sessionId || state.session?.id || null,
            phase: state.kioskVoicePhase || '',
            message: String(details.summary || state.kioskVoiceMessage || '').slice(0, 500),
            details: {
                ...details,
                spoken_segments: [...kioskRealtimeSpokenSegments],
                last_assistant_text: kioskRealtimeLastAssistantText || '',
                pending_user: kioskRealtimePendingUser?.content || '',
                background_active: kioskRealtimeBackgroundWorkActive,
            },
        };
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
            setKioskVoiceStatus('error', 'Bean needs a moment');
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
        setKioskVoiceStatus('working', 'Bean is waking up');
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
        setKioskVoiceStatus('working', 'Bean is waking up');
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
                setKioskVoiceStatus('error', 'Bean needs a moment');
                return false;
            }
            if (!await requestKioskMicrophoneAccess(Boolean(options.requestPermission))) {
                return false;
            }
            stream = await navigator.mediaDevices.getUserMedia({ audio: await kioskAudioConstraints() });
            await rememberKioskMicrophoneFromStream(stream);
            kioskMicrophoneReady = true;
            startKioskRealtimeInputActivityMonitor(stream);
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
                disconnectStatusTimer: 0,
                connected: false,
                sessionId: state.session?.id || null,
            };
            const reconnectFromFailure = (type, details = {}) => {
                if (kioskRealtime !== realtimeState || !state.kioskVoiceEnabled) return;
                window.clearTimeout(realtimeState.disconnectTimer);
                window.clearTimeout(realtimeState.disconnectStatusTimer);
                realtimeState.disconnectTimer = 0;
                realtimeState.disconnectStatusTimer = 0;
                reportKioskRealtimeIssue(type, details);
                stopKioskRealtimeVoiceMode({ preserveStatus: true, preserveReconnect: true });
                scheduleKioskRealtimeReconnect(type, details);
            };
            const waitForTransientDisconnect = (type, details = {}) => {
                if (kioskRealtime !== realtimeState || !state.kioskVoiceEnabled || realtimeState.disconnectTimer) return;
                reportKioskRealtimeIssue(`${type}_transient`, details);
                window.clearTimeout(realtimeState.disconnectStatusTimer);
                realtimeState.disconnectStatusTimer = window.setTimeout(() => {
                    realtimeState.disconnectStatusTimer = 0;
                    if (kioskRealtime !== realtimeState || !state.kioskVoiceEnabled) return;
                    const connectionState = peerConnection.connectionState || '';
                    const iceConnectionState = peerConnection.iceConnectionState || '';
                    if (
                        ['disconnected', 'failed', 'closed'].includes(connectionState)
                        || ['disconnected', 'failed', 'closed'].includes(iceConnectionState)
                        || dataChannel.readyState !== 'open'
                    ) {
                        setKioskVoiceStatus('working', 'Bean is waking up');
                    }
                }, kioskRealtimeTransientStatusMs);
                realtimeState.disconnectTimer = window.setTimeout(() => {
                    realtimeState.disconnectTimer = 0;
                    window.clearTimeout(realtimeState.disconnectStatusTimer);
                    realtimeState.disconnectStatusTimer = 0;
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
                    setRealtimeReadyStatusIfIdle();
                }, kioskRealtimeTransientDisconnectMs);
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
                    window.clearTimeout(realtimeState.disconnectStatusTimer);
                    realtimeState.disconnectTimer = 0;
                    realtimeState.disconnectStatusTimer = 0;
                    setRealtimeReadyStatusIfIdle();
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
                    window.clearTimeout(realtimeState.disconnectStatusTimer);
                    realtimeState.disconnectTimer = 0;
                    realtimeState.disconnectStatusTimer = 0;
                    setRealtimeReadyStatusIfIdle();
                }
            };
            kioskRealtime = realtimeState;
            dataChannel.onopen = () => {
                setKioskVoiceStatus('working', 'Bean is waking up');
                (async () => {
                    try {
                        await refreshRealtimeDashboardContext('realtime_connected');
                    } catch (error) {
                        reportKioskRealtimeIssue('realtime_initial_context_refresh_failed', {
                            message: error?.message || '',
                        });
                    }
                    if (kioskRealtime !== realtimeState || dataChannel.readyState !== 'open') return;
                    realtimeState.connected = true;
                    kioskRealtimeUnavailable = false;
                    kioskRealtimeReconnectAttempts = 0;
                    clearKioskRealtimeReconnect();
                    setKioskVoiceStatus('armed', 'Say hey bean');
                    render();
                })();
            };
            dataChannel.onmessage = (event) => handleKioskRealtimeEvent(event);
            dataChannel.onerror = (event) => {
                const details = {
                    ready_state: dataChannel.readyState,
                    message: event?.message || '',
                };
                if (dataChannel.readyState === 'open') {
                    waitForTransientDisconnect('data_channel_error', details);
                    return;
                }
                reconnectFromFailure('data_channel_error', details);
            };
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
                setKioskVoiceStatus('error', 'Bean needs a moment');
                reportKioskRealtimeIssue('realtime_start_not_retryable', {
                    name: error?.name || '',
                    message: error?.message || '',
                    upstream_message: error?.payload?.upstream_message || '',
                    upstream_status: error?.payload?.status || null,
                });
            } else {
                setKioskVoiceStatus('working', 'Bean is waking up');
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
        kioskRealtimeCurrentUserTurn = null;
        kioskRealtimeAssistantDraft = null;
        kioskRealtimeSuppressNextAssistantPersist = false;
        kioskRealtimeVoiceOnlyAssistant = false;
        kioskRealtimeIgnoreNextFunctionCalls = false;
        setRealtimeBackgroundWorkActive(false);
        kioskRealtimeAwaitingFollowup = false;
        kioskRealtimeLastAssistantText = '';
        kioskRealtimeLastAssistantOutputEndedAt = 0;
        kioskRealtimeSpokenSegments.length = 0;
        kioskRealtimeResponseCreateSentAt = 0;
        kioskRealtimeAwaitingFirstAudio = false;
        clearRealtimeAssistantOutputGuard();
        kioskRealtimeUserTranscriptDrafts.clear();
        window.clearTimeout(kioskRealtimeResponseTimer);
        kioskRealtimeResponseTimer = 0;
        window.clearTimeout(kioskRealtimeToolFallbackTimer);
        kioskRealtimeToolFallbackTimer = 0;
        kioskRealtimeToolFallbackContent = '';
        kioskRealtimeProcessedCalls.clear();
        kioskRealtimeRunWatchTimers.forEach((timer) => window.clearTimeout(timer));
        kioskRealtimeRunWatchTimers.clear();
        clearDeferredRealtimeFunctionOutputs();
        window.clearTimeout(realtime?.disconnectTimer || 0);
        window.clearTimeout(realtime?.disconnectStatusTimer || 0);
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
        stopKioskRealtimeInputActivityMonitor();
        if (!options.preserveStatus) {
            state.kioskVoicePhase = 'idle';
            state.kioskVoiceMessage = '';
        }
    }

    function startKioskRealtimeInputActivityMonitor(stream) {
        stopKioskRealtimeInputActivityMonitor({ keepContext: true });
        const AudioContextClass = window.AudioContext || window.webkitAudioContext;
        if (!AudioContextClass || !stream?.getAudioTracks?.().length) return;
        try {
            kioskRealtimeInputAudioContext ??= new AudioContextClass();
            if (kioskRealtimeInputAudioContext.state === 'suspended') {
                kioskRealtimeInputAudioContext.resume().catch(() => {});
            }
            kioskRealtimeInputAudioSource = kioskRealtimeInputAudioContext.createMediaStreamSource(stream);
            kioskRealtimeInputAnalyser = kioskRealtimeInputAudioContext.createAnalyser();
            kioskRealtimeInputAnalyser.fftSize = 512;
            kioskRealtimeInputAnalyser.smoothingTimeConstant = 0.55;
            kioskRealtimeInputAudioSource.connect(kioskRealtimeInputAnalyser);
            kioskRealtimeInputQuietSince = Date.now();
            monitorKioskRealtimeInputActivity();
        } catch (_) {
            stopKioskRealtimeInputActivityMonitor({ keepContext: true });
        }
    }

    function stopKioskRealtimeInputActivityMonitor(options = {}) {
        window.cancelAnimationFrame(kioskRealtimeInputMonitorFrame);
        kioskRealtimeInputMonitorFrame = 0;
        try { kioskRealtimeInputAudioSource?.disconnect(); } catch (_) {}
        try { kioskRealtimeInputAnalyser?.disconnect(); } catch (_) {}
        kioskRealtimeInputAudioSource = null;
        kioskRealtimeInputAnalyser = null;
        kioskRealtimeInputActiveSince = 0;
        kioskRealtimeInputQuietSince = 0;
        kioskRealtimeInputLastActiveAt = 0;
        if (!options.keepContext && kioskRealtimeInputAudioContext) {
            kioskRealtimeInputAudioContext.close().catch(() => {});
            kioskRealtimeInputAudioContext = null;
        }
    }

    function monitorKioskRealtimeInputActivity() {
        const analyser = kioskRealtimeInputAnalyser;
        if (!analyser || !state.kioskVoiceEnabled) return;
        const samples = new Uint8Array(analyser.fftSize);
        const read = () => {
            if (!kioskRealtimeInputAnalyser || !state.kioskVoiceEnabled) return;
            kioskRealtimeInputAnalyser.getByteTimeDomainData(samples);
            let sum = 0;
            for (let index = 0; index < samples.length; index += 1) {
                const centered = (samples[index] - 128) / 128;
                sum += centered * centered;
            }
            const level = Math.sqrt(sum / samples.length);
            const now = Date.now();
            const active = level > 0.026;
            if (active) {
                kioskRealtimeInputQuietSince = 0;
                kioskRealtimeInputActiveSince ||= now;
                kioskRealtimeInputLastActiveAt = now;
                if (now - kioskRealtimeInputActiveSince > 70) {
                    showOptimisticKioskListening();
                }
            } else {
                kioskRealtimeInputActiveSince = 0;
                kioskRealtimeInputQuietSince ||= now;
                if (now - kioskRealtimeInputQuietSince > 1800) {
                    clearOptimisticKioskListening();
                }
            }
            kioskRealtimeInputMonitorFrame = window.requestAnimationFrame(read);
        };
        kioskRealtimeInputMonitorFrame = window.requestAnimationFrame(read);
    }

    function showOptimisticKioskListening() {
        if (!kioskRealtimeConnected()) return;
        if (realtimeAssistantOutputActive() || realtimeAssistantRecentlyOutput()) return;
        if (realtimeBackgroundWorkPending() && !kioskConversationActive) return;
        if (!['idle', 'armed', 'listening'].includes(state.kioskVoicePhase)) return;
        setKioskVoiceStatus('listening', 'listening');
    }

    function clearOptimisticKioskListening() {
        if (!kioskRealtimeConnected()) return;
        if (kioskConversationActive || kioskRealtimePendingUser || kioskRealtimeResponseTimer || realtimeAssistantOutputActive()) return;
        if (Date.now() - kioskRealtimeInputLastActiveAt < 1800) return;
        if (state.kioskVoicePhase !== 'listening') return;
        setKioskVoiceStatus('armed', 'Say hey bean');
    }

    function handleKioskRealtimeEvent(event) {
        let payload = null;
        try {
            payload = JSON.parse(event.data);
        } catch (_) {
            return;
        }
        const type = payload?.type || '';
        if (type.startsWith('input_audio_buffer.') || type.startsWith('conversation.item.input_audio_transcription.')) {
            if (!kioskRealtimeConnected()) {
                setKioskVoiceStatus('working', 'Bean is waking up');
                return;
            }
        }
        if (type === 'input_audio_buffer.speech_started') {
            if (kioskConversationActive) {
                window.clearTimeout(kioskConversationTimer);
                kioskConversationTimer = 0;
            }
            if (realtimeAssistantRecentlyOutput()) return;
            if (kioskConversationActive || ['idle', 'armed'].includes(state.kioskVoicePhase)) {
                setKioskVoiceStatus('listening', 'listening');
            }
            return;
        }
        if (type === 'input_audio_buffer.speech_stopped') {
            if (realtimeAssistantRecentlyOutput()) return;
            if (kioskConversationActive) {
                setKioskVoiceStatus('listening', 'listening');
                armKioskConversationTimeout(kioskRealtimeAwaitingFollowup ? 30000 : undefined);
            }
            return;
        }
        if (type === 'conversation.item.input_audio_transcription.delta') {
            handleRealtimeUserTranscriptDelta(payload);
            return;
        }
        if (type === 'conversation.item.input_audio_transcription.segment') {
            handleRealtimeUserTranscriptSegment(payload);
            return;
        }
        if (type === 'conversation.item.input_audio_transcription.completed') {
            handleRealtimeUserTranscript(payload);
            return;
        }
        if (type === 'response.created') {
            clearRealtimeToolFallback();
            markRealtimeAssistantOutputActive(5000, { started: true });
            setKioskVoiceStatus('responding', 'Bean is answering');
            return;
        }
        if (type === 'response.audio.delta') {
            if (kioskRealtimeAwaitingFirstAudio) {
                kioskRealtimeAwaitingFirstAudio = false;
                logKioskRealtimeVoiceTrace('realtime_voice_first_audio_delta', {
                    summary: 'First realtime audio delta received.',
                    ms_after_response_create: kioskRealtimeResponseCreateSentAt
                        ? Date.now() - kioskRealtimeResponseCreateSentAt
                        : null,
                });
            }
            markRealtimeAssistantOutputActive(2500);
            setKioskVoiceStatus('responding', 'Bean is answering');
            return;
        }
        if (type === 'response.audio_transcript.delta' || type === 'response.output_text.delta') {
            appendRealtimeAssistantDelta(payload);
            return;
        }
        if (type === 'response.audio.done') {
            markRealtimeAssistantOutputActive(realtimeAssistantOutputRemainingMs());
            scheduleRealtimeTurnStatusAfterOutput();
            return;
        }
        if (type === 'response.audio_transcript.done' || type === 'response.output_text.done') {
            finishRealtimeAssistantTranscript(payload);
            return;
        }
        if (type === 'response.function_call_arguments.done') {
            queueRealtimeFunctionCall(payload.name, payload.call_id, payload.arguments);
            return;
        }
        if (type === 'response.done') {
            if (kioskRealtimeAwaitingFirstAudio) {
                kioskRealtimeAwaitingFirstAudio = false;
                logKioskRealtimeVoiceTrace('realtime_voice_response_done_without_audio_delta', {
                    summary: 'Realtime response completed before an audio delta arrived.',
                    ms_after_response_create: kioskRealtimeResponseCreateSentAt
                        ? Date.now() - kioskRealtimeResponseCreateSentAt
                        : null,
                });
            }
            markRealtimeAssistantOutputActive(realtimeAssistantOutputRemainingMs());
            processRealtimeResponseDone(payload);
            return;
        }
        if (type === 'error') {
            const message = beanRealtimeUserStatusMessage(payload?.error?.message || 'Bean needs a moment');
            setKioskVoiceStatus(message.phase, message.text);
        }
    }

    function beanRealtimeUserStatusMessage(message) {
        const text = String(message || '').trim();
        if (/session\.type|missing required parameter|invalid_request_error/i.test(text)) {
            reportKioskRealtimeIssue('realtime_protocol_error', { message: text });
            if (kioskRealtimeConnected()) {
                return { phase: 'armed', text: 'Say hey bean' };
            }
            return state.kioskVoiceEnabled
                ? { phase: 'working', text: 'Bean is waking up' }
                : { phase: 'error', text: 'Bean needs a moment' };
        }
        if (/\b(?:voice|connect|connection|realtime|webrtc|client secret|session|sdp|ice)\b/i.test(text)) {
            return state.kioskVoiceEnabled
                ? { phase: 'working', text: 'Bean is waking up' }
                : { phase: 'error', text: 'Bean needs a moment' };
        }

        return { phase: 'error', text: text || 'Bean needs a moment' };
    }

    function markRealtimeAssistantOutputActive(durationMs = 3500, options = {}) {
        if (options.started || !kioskRealtimeAssistantOutputStartedAt) {
            kioskRealtimeAssistantOutputStartedAt = Date.now();
        }
        kioskRealtimeSuppressInputUntil = Math.max(kioskRealtimeSuppressInputUntil, Date.now() + durationMs);
    }

    function realtimeAssistantOutputActive() {
        return Date.now() < kioskRealtimeSuppressInputUntil;
    }

    function realtimeAssistantRecentlyOutput(bufferMs = 4200) {
        return realtimeAssistantOutputActive()
            || Boolean(kioskRealtimeLastAssistantOutputEndedAt && Date.now() - kioskRealtimeLastAssistantOutputEndedAt < bufferMs)
            || state.kioskVoicePhase === 'responding';
    }

    function realtimeAssistantOutputRemainingMs() {
        const text = String(kioskRealtimeAssistantDraft?.content || '').trim();
        const words = text ? text.split(/\s+/).filter(Boolean).length : 0;
        const estimatedTotalMs = Math.min(34000, Math.max(3200, Math.round(words * 430) + 2200));
        const elapsedMs = kioskRealtimeAssistantOutputStartedAt ? Date.now() - kioskRealtimeAssistantOutputStartedAt : 0;
        return Math.min(28000, Math.max(4200, estimatedTotalMs - elapsedMs + 2600));
    }

    function scheduleRealtimeTurnStatusAfterOutput() {
        window.clearTimeout(kioskRealtimeAssistantOutputTimer);
        const wait = Math.max(300, kioskRealtimeSuppressInputUntil - Date.now() + 250);
        kioskRealtimeAssistantOutputTimer = window.setTimeout(() => {
            kioskRealtimeAssistantOutputTimer = 0;
            if (realtimeAssistantOutputActive()) {
                scheduleRealtimeTurnStatusAfterOutput();
                return;
            }
            kioskRealtimeAssistantOutputStartedAt = 0;
            kioskRealtimeLastAssistantOutputEndedAt = Date.now();
            finishRealtimeTurnStatus();
        }, wait);
    }

    function clearRealtimeAssistantOutputGuard() {
        kioskRealtimeSuppressInputUntil = 0;
        kioskRealtimeAssistantOutputStartedAt = 0;
        kioskRealtimeLastAssistantOutputEndedAt = 0;
        window.clearTimeout(kioskRealtimeAssistantOutputTimer);
        kioskRealtimeAssistantOutputTimer = 0;
        window.clearTimeout(kioskRealtimeDeferredWorkingStatusTimer);
        kioskRealtimeDeferredWorkingStatusTimer = 0;
    }

    function setRealtimeBackgroundWorkActive(active, context = null) {
        kioskRealtimeBackgroundWorkActive = Boolean(active);
        if (kioskRealtimeBackgroundWorkActive) {
            window.clearTimeout(kioskConversationTimer);
            kioskConversationTimer = 0;
            const requestedWork = String(context?.userContent || kioskRealtimePendingUser?.content || '').trim();
            if (requestedWork) ensureRealtimeRequestWorkItem(requestedWork);
            if (context) {
                kioskRealtimeBackgroundProgressContext = {
                    userContent: String(context.userContent || '').trim(),
                    quickReplyText: String(context.quickReplyText || '').trim(),
                    startedAt: Date.now(),
                    updatesSent: 0,
                };
            } else if (!kioskRealtimeBackgroundProgressContext) {
                kioskRealtimeBackgroundProgressContext = {
                    userContent: String(kioskRealtimePendingUser?.content || '').trim(),
                    quickReplyText: String(kioskRealtimeAssistantDraft?.content || '').trim(),
                    startedAt: Date.now(),
                    updatesSent: 0,
                };
            }
            scheduleRealtimeBackgroundProgressUpdates();
            showRealtimeWorkingInBackgroundWhenReady();
            return;
        }
        clearRealtimeBackgroundProgressUpdates();
        kioskRealtimeBackgroundProgressContext = null;
        completeActiveBeanWorkItems();
    }

    function realtimeBackgroundWorkPending() {
        return kioskRealtimeBackgroundWorkActive
            || kioskRealtimeRunWatchTimers.size > 0
            || Boolean(kioskRealtimePendingBackgroundResult);
    }

    function scheduleRealtimeBackgroundProgressUpdates() {
        clearRealtimeBackgroundProgressUpdates();
        [
            { elapsedMs: 20000, instruction: 'Say one brief sentence that you are still working on the request. Do not repeat prior wording.' },
        ].forEach((checkpoint) => {
            const timer = window.setTimeout(() => {
                kioskRealtimeBackgroundProgressTimers.delete(timer);
                sendRealtimeBackgroundProgressUpdate(checkpoint);
            }, checkpoint.elapsedMs);
            kioskRealtimeBackgroundProgressTimers.add(timer);
        });
    }

    function clearRealtimeBackgroundProgressUpdates() {
        kioskRealtimeBackgroundProgressTimers.forEach((timer) => window.clearTimeout(timer));
        kioskRealtimeBackgroundProgressTimers.clear();
    }

    function sendRealtimeBackgroundProgressUpdate(checkpoint) {
        if (!kioskRealtimeBackgroundWorkActive || !kioskConversationActive || !kioskRealtimeConnected()) return;
        if (realtimeAssistantRecentlyOutput()) {
            const timer = window.setTimeout(() => {
                kioskRealtimeBackgroundProgressTimers.delete(timer);
                sendRealtimeBackgroundProgressUpdate(checkpoint);
            }, Math.max(500, kioskRealtimeSuppressInputUntil - Date.now() + 500));
            kioskRealtimeBackgroundProgressTimers.add(timer);
            return;
        }
        const dataChannel = kioskRealtime?.dataChannel;
        if (dataChannel?.readyState !== 'open') return;
        const context = kioskRealtimeBackgroundProgressContext || {};
        const alreadySpoken = [...new Set([
            ...kioskRealtimeSpokenSegments,
            context.quickReplyText,
            kioskRealtimeLastAssistantText,
        ].map((item) => String(item || '').trim()).filter(Boolean))].slice(-6);
        kioskRealtimeBackgroundProgressContext = {
            ...context,
            updatesSent: Number(context.updatesSent || 0) + 1,
        };
        kioskRealtimeSuppressNextAssistantPersist = true;
        kioskRealtimeVoiceOnlyAssistant = true;
        kioskRealtimeIgnoreNextFunctionCalls = true;
        logKioskRealtimeVoiceTrace('realtime_voice_progress_prompt', {
            summary: 'Sending realtime background progress prompt.',
            user_request: context.userContent || '',
            elapsed_ms: checkpoint.elapsedMs,
            already_spoken: alreadySpoken,
            instruction: checkpoint.instruction,
        });
        dataChannel.send(JSON.stringify({
            type: 'conversation.item.create',
            item: {
                type: 'message',
                role: 'user',
                content: [{
                    type: 'input_text',
                    text: JSON.stringify({
                        realtime_progress_update: true,
                        user_request: context.userContent || '',
                        elapsed_ms: checkpoint.elapsedMs,
                        already_spoken: alreadySpoken,
                        instruction: checkpoint.instruction,
                        rules: [
                            'Speak one short sentence only.',
                            'Do not call tools.',
                            'Do not mention tools, models, connections, or voice.',
                            'Do not repeat or paraphrase anything in already_spoken.',
                        ],
                    }),
                }],
            },
        }));
        sendRealtimeResponseCreate();
    }

    function realtimeAssistantAwaitingFollowup(text = '') {
        const normalized = String(text || '').trim();
        if (!normalized) return false;
        return /[?？]\s*$/.test(normalized)
            || /\b(?:do you want|would you like|want me to|should i|should we|do you need|need me to|would that help|sound good)\b/i.test(normalized);
    }

    function setRealtimeReadyStatusIfIdle() {
        if (!kioskRealtimeConnected()) {
            if (state.kioskVoiceEnabled && !kioskRealtimeStarting) {
                setKioskVoiceStatus('working', 'Bean is waking up');
            }
            return;
        }
        if (
            realtimeAssistantRecentlyOutput()
            || kioskRealtimeBackgroundWorkActive
            || kioskConversationActive
            || ['heard', 'working', 'responding'].includes(state.kioskVoicePhase)
        ) {
            return;
        }
        setKioskVoiceStatus('armed', 'Say hey bean');
    }

    function realtimeUserTranscriptLooksLikeEcho(transcript) {
        const normalized = normalizedRealtimeTranscript(transcript);
        if (!normalized) return true;
        if (!realtimeAssistantRecentlyOutput()) return false;
        if (/^(?:thank you|thanks|got it|okay|ok|alright|all right|sure|yep|yes|yeah)$/i.test(normalized)) return true;

        const assistant = normalizedRealtimeTranscript(kioskRealtimeLastAssistantText || kioskRealtimeAssistantDraft?.content || '');
        if (!assistant) return false;
        if (assistant.includes(normalized) && normalized.split(/\s+/).length <= 8) return true;
        return normalized.length >= 12 && transcriptSimilarity(normalized, assistant) > 0.72;
    }

    function realtimeTranscriptMentionsBean(transcript) {
        return /\b(?:bean|beans|ben|beam|beem|bein|bing|heybean)\b/i.test(String(transcript || ''));
    }

    function realtimeTranscriptLooksLikeStatusCheck(transcript) {
        const normalized = normalizedRealtimeTranscript(transcript);
        return /\b(?:are you done|is it done|did it finish|did that finish|did it work|did that work|finished|complete|completed|created|scheduled|added|status|still working|what happened|how'?s it going|hows it going)\b/.test(normalized);
    }

    function realtimeWakeContinuationActive() {
        return Boolean(kioskRealtimeWakeContinuationUntil && Date.now() < kioskRealtimeWakeContinuationUntil);
    }

    function realtimeTranscriptLooksLikeFollowup(transcript) {
        const normalized = normalizedRealtimeTranscript(transcript);
        if (!normalized) return false;
        return /^(?:what else|anything else|anything more|and\b|also\b|plus\b|what about\b|how about\b|then\b|next\b|do that\b|go ahead\b|sounds good\b)\b/.test(normalized)
            || /^(?:can you|could you|would you)\s+(?:also|check|show|tell|add|create|move|update|delete|remove|cancel|remind|schedule)\b/.test(normalized)
            || realtimeTranscriptLooksLikeAppWorkRequest(normalized);
    }

    function realtimeTranscriptLooksLikeAppWorkRequest(transcript) {
        const command = normalizedVoiceCommand(transcript);
        if (!command || !voiceCommandNeedsAgentWork(command)) return false;
        return /\b(?:add|create|put|move|reschedule|schedule|update|change|delete|remove|cancel|complete|finish|mark|remind|remember|plan|organize|prioritize)\b/.test(command)
            || /\b(?:calendar|calendars|event|events|task|tasks|todo|to do|reminder|reminders|agenda|workspace|workspaces|google calendar)\b/.test(command);
    }

    function realtimeTranscriptCanContinueWithoutWake(transcript) {
        const normalized = normalizedRealtimeTranscript(transcript);
        if (!normalized) return false;
        if (realtimeTranscriptMentionsBean(transcript)) return true;
        if (kioskRealtimeAwaitingFollowup) return true;
        if (realtimeWakeContinuationActive()) return true;
        if (kioskConversationActive && realtimeTranscriptLooksLikeFollowup(normalized)) return true;
        if (kioskRealtimeResponseTimer && kioskRealtimePendingUser && !kioskRealtimePendingUser.persisted) return true;
        if (realtimeBackgroundWorkPending() && realtimeTranscriptLooksLikeStatusCheck(normalized)) return true;
        return false;
    }

    function realtimeCommandShouldQueueImmediately(transcript) {
        const command = normalizedVoiceCommand(transcript);
        if (!command || !voiceCommandRequiresBackgroundWork(command)) return false;
        return /\b(?:add|create|put|move|reschedule|schedule|update|change|delete|remove|cancel|complete|finish|mark|remind|remember)\b/.test(command)
            && /\b(?:calendar|event|events|task|tasks|todo|to do|reminder|reminders|agenda|workspace|workspaces|memory|remember)\b/.test(command);
    }

    function realtimeQueuedWorkAcknowledgement(transcript) {
        const command = normalizedVoiceCommand(transcript);
        if (/\b(?:calendar|event|events|agenda|google calendar)\b/.test(command)) return "Got it. I'll update your calendar now.";
        if (/\b(?:task|tasks|todo|to do)\b/.test(command)) return "Got it. I'll update your tasks now.";
        if (/\b(?:reminder|reminders)\b/.test(command)) return "Got it. I'll set that reminder now.";
        if (/\b(?:remember|memory)\b/.test(command)) return "Got it. I'll save that now.";
        return "Got it. I'll take care of that now.";
    }

    function realtimeTranscriptRequestsPoliteClose(transcript) {
        const normalized = normalizedRealtimeTranscript(transcript);
        return /^(?:thanks|thank you|thx|that'?s all|we'?re done|we are done)$/.test(normalized)
            || (realtimeTranscriptMentionsBean(transcript) && /^(?:thanks|thank you|thx)\b/.test(normalized))
            || /\b(?:thanks|thank you),?\s*(?:that'?s all|we'?re done|we are done)\b/i.test(String(transcript || ''));
    }

    function realtimeTranscriptRequestsCancel(raw, command, isWakeTurn) {
        if (isWakeTurn && voiceCancelRequested(command)) return true;
        if (realtimeTranscriptMentionsBean(raw) && voiceCancelRequested(raw)) return true;
        if (realtimeBackgroundWorkPending() || realtimeAssistantRecentlyOutput()) return false;
        return voiceCancelRequested(raw);
    }

    function normalizedRealtimeTranscript(transcript) {
        return String(transcript || '')
            .toLowerCase()
            .replace(/[^a-z0-9\s']/g, ' ')
            .replace(/\s+/g, ' ')
            .trim();
    }

    function transcriptSimilarity(a, b) {
        const aWords = new Set(String(a || '').split(/\s+/).filter(Boolean));
        const bWords = new Set(String(b || '').split(/\s+/).filter(Boolean));
        if (!aWords.size || !bWords.size) return 0;
        let overlap = 0;
        aWords.forEach((word) => {
            if (bWords.has(word)) overlap += 1;
        });
        return overlap / Math.min(aWords.size, bWords.size);
    }

    function handleRealtimeUserTranscript(payload) {
        const key = realtimeTranscriptDraftKey(payload);
        const draft = key ? (kioskRealtimeUserTranscriptDrafts.get(key) || '') : '';
        const raw = bestRealtimeTranscript(String(payload.transcript || '').trim(), draft);
        if (!raw) return;
        if (key) kioskRealtimeUserTranscriptDrafts.delete(key);
        if (realtimeTranscriptLooksSynthetic(raw)) {
            if (realtimeAssistantOutputActive()) return;
            setKioskVoiceStatus('armed', 'Say hey bean');
            return;
        }
        const command = commandAfterWakePhrase(raw);
        const isWakeTurn = command !== null;
        if (realtimeAssistantOutputActive()) {
            if (realtimeTranscriptRequestsCancel(raw, command, isWakeTurn)) {
                cancelKioskVoiceCapture();
                return;
            }
            if (
                !kioskConversationActive
                || realtimeUserTranscriptLooksLikeEcho(raw)
                || (!isWakeTurn && !realtimeTranscriptCanContinueWithoutWake(raw))
            ) {
                return;
            }
            clearRealtimeAssistantOutputGuard();
        }
        if (realtimeUserTranscriptLooksLikeEcho(raw)) {
            return;
        }
        if (realtimeTranscriptRequestsCancel(raw, command, isWakeTurn)) {
            cancelKioskVoiceCapture();
            return;
        }
        if (realtimeTranscriptRequestsPoliteClose(raw)) {
            if (!realtimeBackgroundWorkPending() || isWakeTurn || realtimeTranscriptMentionsBean(raw)) {
                endKioskConversation();
            }
            return;
        }
        if (!isWakeTurn && !kioskConversationActive) {
            setKioskVoiceStatus('armed', 'Say hey bean');
            return;
        }
        if (!isWakeTurn && kioskConversationActive && !realtimeTranscriptCanContinueWithoutWake(raw)) {
            armKioskConversationTimeout(kioskRealtimeAwaitingFollowup ? 30000 : undefined);
            return;
        }
        window.clearTimeout(kioskConversationTimer);
        kioskConversationTimer = 0;
        if (isWakeTurn) {
            beginKioskConversation();
            kioskRealtimeWakeContinuationUntil = Date.now() + kioskRealtimeWakeContinuationMs;
        }
        const content = (isWakeTurn ? command : raw).trim();
        if (!content) {
            logKioskRealtimeVoiceTrace('realtime_voice_wake_only', {
                summary: 'Wake phrase heard without a command yet.',
                transcript: raw,
            });
            setKioskVoiceStatus('listening', 'Go ahead');
            armKioskConversationTimeout(kioskRealtimeWakeContinuationMs);
            return;
        }
        kioskRealtimeAwaitingFollowup = false;
        showKioskHeardTranscript(content, {
            allowArmed: true,
            phase: 'heard',
            force: true,
            holdMs: kioskRealtimeTurnDebounceMs + 900,
        });
        const shouldAppendToPendingTurn = Boolean(kioskRealtimeResponseTimer && kioskRealtimePendingUser && !kioskRealtimePendingUser.persisted && !isWakeTurn);
        if (shouldAppendToPendingTurn) {
            kioskRealtimePendingUser.content = `${kioskRealtimePendingUser.content} ${content}`.replace(/\s+/g, ' ').trim();
            logKioskRealtimeVoiceTrace('realtime_voice_pending_transcript_appended', {
                summary: 'Appended transcript to a pending realtime turn.',
                appended_content: content,
                full_content: kioskRealtimePendingUser.content,
            });
        } else {
            kioskRealtimePendingUser = {
                itemId: payload.item_id || `rt-user-${Date.now()}`,
                content,
                startedAt: Date.now(),
                persisted: false,
            };
        }
        kioskRealtimeWakeContinuationUntil = Date.now() + kioskRealtimeTurnDebounceMs + 500;
        kioskRealtimeCurrentUserTurn = { ...kioskRealtimePendingUser };
        upsertRealtimeLocalMessage({
            id: `rt-user-${kioskRealtimePendingUser.itemId}`,
            role: 'user',
            content: kioskRealtimePendingUser.content,
            metadata: { local_realtime_turn: true },
        });
        if (realtimeBackgroundWorkPending() && realtimeTranscriptLooksLikeStatusCheck(content)) {
            logKioskRealtimeVoiceTrace('realtime_voice_status_check_while_background_active', {
                summary: 'Answered status check locally while background work is active.',
                user_content: content,
            });
            speakKioskAcknowledgement("I'm still working on that.", {
                shouldPlay: () => kioskConversationActive && realtimeBackgroundWorkPending(),
            }).catch(() => {});
            armKioskConversationTimeout(30000);
            return;
        }
        if (realtimeCommandShouldQueueImmediately(content)) {
            queueImmediateRealtimeBackgroundWork(content);
            return;
        }
        scheduleRealtimeResponseCreate();
    }

    function handleRealtimeUserTranscriptDelta(payload) {
        const delta = String(payload.delta || '').trim();
        if (!delta) return;
        const key = realtimeTranscriptDraftKey(payload);
        const previous = key ? (kioskRealtimeUserTranscriptDrafts.get(key) || '') : '';
        const draft = mergeRealtimeTranscriptDelta(previous, delta);
        if (key) kioskRealtimeUserTranscriptDrafts.set(key, draft);
        if (realtimeUserTranscriptLooksLikeEcho(delta)) return;
        if (realtimeAssistantOutputActive()) return;
        const hasWakePhrase = commandAfterWakePhrase(delta) !== null;
        if (kioskConversationActive && !hasWakePhrase && !realtimeTranscriptCanContinueWithoutWake(delta)) return;
        if (kioskConversationActive || hasWakePhrase) {
            window.clearTimeout(kioskConversationTimer);
            kioskConversationTimer = 0;
        }
        showRealtimeHeardTranscript(draft);
    }

    function handleRealtimeUserTranscriptSegment(payload) {
        const text = String(payload.text || '').trim();
        if (!text) return;
        const key = realtimeTranscriptDraftKey(payload);
        if (key) {
            const previous = kioskRealtimeUserTranscriptDrafts.get(key) || '';
            kioskRealtimeUserTranscriptDrafts.set(key, mergeRealtimeTranscriptDelta(previous, text));
        }
        if (realtimeUserTranscriptLooksLikeEcho(text)) return;
        if (realtimeAssistantOutputActive()) return;
        const hasWakePhrase = commandAfterWakePhrase(text) !== null;
        if (kioskConversationActive && !hasWakePhrase && !realtimeTranscriptCanContinueWithoutWake(text)) return;
        if (kioskConversationActive || hasWakePhrase) {
            window.clearTimeout(kioskConversationTimer);
            kioskConversationTimer = 0;
        }
        showRealtimeHeardTranscript(text);
    }

    function realtimeTranscriptDraftKey(payload) {
        const itemId = String(payload?.item_id || payload?.itemId || '').trim();
        if (!itemId) return '';
        const contentIndex = Number.isFinite(Number(payload?.content_index)) ? Number(payload.content_index) : 0;
        return `${itemId}:${contentIndex}`;
    }

    function bestRealtimeTranscript(primary, fallback) {
        const first = String(primary || '').replace(/\s+/g, ' ').trim();
        const second = String(fallback || '').replace(/\s+/g, ' ').trim();
        if (!first) return second;
        if (!second) return first;
        const normalizedFirst = normalizedRealtimeTranscript(first);
        const normalizedSecond = normalizedRealtimeTranscript(second);
        if (normalizedFirst === normalizedSecond) return first.length >= second.length ? first : second;
        if (normalizedFirst.includes(normalizedSecond)) return first;
        if (normalizedSecond.includes(normalizedFirst)) return second;
        const firstWords = normalizedFirst.split(/\s+/).filter(Boolean).length;
        const secondWords = normalizedSecond.split(/\s+/).filter(Boolean).length;
        if (secondWords >= firstWords + 2 && voiceCommandNeedsAgentWork(second)) return second;
        if (firstWords >= secondWords + 2 && voiceCommandNeedsAgentWork(first)) return first;
        return first.length >= second.length ? first : second;
    }

    function mergeRealtimeTranscriptDelta(previous, delta) {
        const prior = String(previous || '');
        const next = String(delta || '');
        if (!prior) return next.replace(/\s+/g, ' ').trim();
        if (!next) return prior.replace(/\s+/g, ' ').trim();
        if (next.toLowerCase().startsWith(prior.toLowerCase())) {
            return next.replace(/\s+/g, ' ').trim();
        }
        if (prior.toLowerCase().endsWith(next.toLowerCase())) {
            return prior.replace(/\s+/g, ' ').trim();
        }
        return `${prior}${/^\s|[,.!?;:]/.test(next) ? '' : ' '}${next}`.replace(/\s+/g, ' ').trim();
    }

    function showRealtimeHeardTranscript(transcript) {
        const raw = String(transcript || '').trim();
        if (!raw || realtimeTranscriptLooksSynthetic(raw)) return;
        if (realtimeUserTranscriptLooksLikeEcho(raw)) return;
        const command = commandAfterWakePhrase(raw);
        if (!kioskConversationActive && command === null) return;
        if (kioskConversationActive && command === null && !realtimeTranscriptCanContinueWithoutWake(raw)) return;
        showKioskHeardTranscript(raw, {
            allowArmed: command !== null,
            phase: command !== null || kioskConversationActive ? 'heard' : 'armed',
            force: true,
        });
    }

    function realtimeTranscriptLooksSynthetic(transcript) {
        const normalized = String(transcript || '')
            .toLowerCase()
            .replace(/[^a-z0-9\s]/g, ' ')
            .replace(/\s+/g, ' ')
            .trim();
        if (!normalized) return true;
        if (normalized === 'bean heybean can you hear me calendar tasks reminders') return true;
        if (normalized === 'hey bean bean heybean can you hear me calendar tasks reminders') return true;
        if (/^(?:bean\s+)?heybean\s+can you hear me calendar tasks reminders$/.test(normalized)) return true;
        return false;
    }

    function scheduleRealtimeResponseCreate() {
        window.clearTimeout(kioskRealtimeResponseTimer);
        clearRealtimeToolFallback();
        if (state.kioskVoicePhase !== 'heard') {
            setKioskVoiceStatus('listening', 'listening');
        }
        kioskRealtimeResponseTimer = window.setTimeout(() => {
            kioskRealtimeResponseTimer = 0;
            if (!state.kioskVoiceEnabled || !kioskRealtimeConnected() || !kioskConversationActive) return;
            const content = String(kioskRealtimePendingUser?.content || '').trim();
            if (!content) return;
            kioskRealtimeWakeContinuationUntil = 0;
            armRealtimeToolFallback(content);
            setKioskVoiceStatus('working', 'thinking');
            kioskRealtimeResponseCreateSentAt = Date.now();
            kioskRealtimeAwaitingFirstAudio = true;
            logKioskRealtimeVoiceTrace('realtime_voice_response_create_sent', {
                summary: 'Sent realtime response.create for user turn.',
                user_content: content,
                ms_after_turn_started: kioskRealtimePendingUser?.startedAt
                    ? kioskRealtimeResponseCreateSentAt - kioskRealtimePendingUser.startedAt
                    : null,
            });
            if (!sendRealtimeResponseCreate()) {
                kioskRealtimeAwaitingFirstAudio = false;
                recoverKioskRealtimeAfterSendFailure('response_create_unavailable');
            }
        }, kioskRealtimeTurnDebounceMs);
    }

    function appendRealtimeAssistantDelta(payload) {
        const delta = String(payload.delta || '');
        if (!delta) return;
        clearRealtimeToolFallback();
        markRealtimeAssistantOutputActive(4500);
        kioskRealtimeAwaitingFollowup = false;
        const draft = ensureRealtimeAssistantDraft(payload.response_id || payload.item_id);
        draft.content += delta;
        if (!kioskRealtimeVoiceOnlyAssistant) {
            upsertRealtimeLocalMessage(draft);
        }
        setKioskVoiceStatus('responding', 'Bean is answering');
    }

    function finishRealtimeAssistantTranscript(payload) {
        const text = String(payload.transcript || payload.text || '').trim();
        if (!text) return;
        clearRealtimeToolFallback();
        markRealtimeAssistantOutputActive(2500);
        kioskRealtimeAwaitingFollowup = realtimeAssistantAwaitingFollowup(text);
        const draft = ensureRealtimeAssistantDraft(payload.response_id || payload.item_id);
        draft.content = text;
        kioskRealtimeLastAssistantText = text;
        recordRealtimeSpokenSegment(text);
        logKioskRealtimeVoiceTrace('realtime_voice_spoken', {
            summary: 'Realtime assistant spoken transcript completed.',
            response_id: payload.response_id || null,
            item_id: payload.item_id || null,
            text,
            voice_only: kioskRealtimeVoiceOnlyAssistant,
            suppress_persist: kioskRealtimeSuppressNextAssistantPersist,
            ignore_function_calls: kioskRealtimeIgnoreNextFunctionCalls,
        });
        if (!kioskRealtimeVoiceOnlyAssistant) {
            upsertRealtimeLocalMessage(draft);
        }
    }

    function recordRealtimeSpokenSegment(text) {
        const cleanText = String(text || '').replace(/\s+/g, ' ').trim();
        if (!cleanText) return;
        const last = kioskRealtimeSpokenSegments[kioskRealtimeSpokenSegments.length - 1] || '';
        if (normalizeComparableSpeech(last) === normalizeComparableSpeech(cleanText)) return;
        kioskRealtimeSpokenSegments.push(cleanText);
        if (kioskRealtimeSpokenSegments.length > 8) {
            kioskRealtimeSpokenSegments.splice(0, kioskRealtimeSpokenSegments.length - 8);
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

    function queueRealtimeFunctionCall(name, callId, rawArguments = '{}') {
        if (!name) return;
        const callKey = callId || `${name}-${rawArguments}`;
        const call = { type: 'function_call', name, call_id: callId, arguments: rawArguments };
        const existingIndex = kioskRealtimePendingFunctionCalls.findIndex((item) => {
            return (item.call_id || `${item.name}-${item.arguments}`) === callKey;
        });
        if (existingIndex >= 0) {
            kioskRealtimePendingFunctionCalls[existingIndex] = call;
            return;
        }
        kioskRealtimePendingFunctionCalls.push(call);
    }

    function processRealtimeResponseDone(payload) {
        const output = normalizeList(payload?.response?.output);
        const responseAssistantText = String(kioskRealtimeAssistantDraft?.content || realtimeTextFromResponseOutput(output)).trim();
        const functionCalls = mergeRealtimeFunctionCalls([
            ...kioskRealtimePendingFunctionCalls,
            ...output.filter((item) => item?.type === 'function_call'),
        ]);
        reportKioskRealtimeUsage(payload, functionCalls);
        kioskRealtimePendingFunctionCalls = [];
        const hasFunctionCall = functionCalls.length > 0;
        const assistantAnswered = responseAssistantText !== '';
        if (assistantAnswered) {
            kioskRealtimeLastAssistantText = responseAssistantText;
            recordRealtimeSpokenSegment(responseAssistantText);
        }
        const activeUserTurn = kioskRealtimePendingUser || kioskRealtimeCurrentUserTurn;
        const pendingUserContent = String(activeUserTurn?.content || '').trim();
        const functionCallsAreBackgroundQueueOnly = functionCalls.length > 0
            && functionCalls.every((item) => item?.name === 'queue_bean_work');
        const backgroundQueueAllowed = realtimeSpokenAnswerAllowsBackgroundQueue(
            pendingUserContent,
            responseAssistantText,
        );
        const reactivatedConversation = assistantAnswered && pendingUserContent && !kioskConversationActive;
        if (reactivatedConversation) {
            kioskConversationActive = true;
        }
        logKioskRealtimeVoiceTrace('realtime_voice_response_done', {
            summary: 'Realtime response completed.',
            response_id: payload?.response?.id || payload?.response_id || null,
            user_content: pendingUserContent,
            pending_user_present: Boolean(kioskRealtimePendingUser?.content),
            current_user_turn_present: Boolean(kioskRealtimeCurrentUserTurn?.content),
            reactivated_conversation: reactivatedConversation,
            assistant_text: responseAssistantText,
            assistant_answered: assistantAnswered,
            function_calls: functionCalls.map((item) => ({
                name: item?.name || '',
                call_id: item?.call_id || '',
                arguments: item?.arguments || '',
            })),
            background_queue_allowed: backgroundQueueAllowed,
        });
        if (kioskRealtimeIgnoreNextFunctionCalls) {
            kioskRealtimeIgnoreNextFunctionCalls = false;
            if (hasFunctionCall) {
                logKioskRealtimeVoiceTrace('realtime_voice_tool_calls_skipped', {
                    summary: 'Skipped realtime tool calls for a speech-only internal response.',
                    reason: 'ignore_function_calls',
                    assistant_text: responseAssistantText,
                    function_calls: functionCalls.map((item) => ({
                        name: item?.name || '',
                        call_id: item?.call_id || '',
                        arguments: item?.arguments || '',
                    })),
                });
                functionCalls.forEach((item) => {
                    sendRealtimeFunctionOutput(item.call_id, {
                        ok: true,
                        skipped: true,
                        message: 'This speech-only update should not call tools.',
                    }, { createResponse: false });
                });
                persistRealtimeConversationTurn();
                finishRealtimeTurnStatus();
                return;
            }
        }
        if (hasFunctionCall && !pendingUserContent) {
            clearRealtimeToolFallback();
            logKioskRealtimeVoiceTrace('realtime_voice_tool_calls_skipped', {
                summary: 'Skipped realtime tool calls because there is no active user turn.',
                reason: 'missing_pending_user',
                phase: state.kioskVoicePhase || '',
                assistant_text: responseAssistantText,
                function_calls: functionCalls.map((item) => ({
                    name: item?.name || '',
                    call_id: item?.call_id || '',
                    arguments: item?.arguments || '',
                })),
            });
            functionCalls.forEach((item) => {
                sendRealtimeFunctionOutput(item.call_id, {
                    ok: true,
                    skipped: true,
                    message: 'No active user turn is available for this tool call.',
                }, { createResponse: false });
            });
            kioskRealtimeAssistantDraft = null;
            kioskRealtimeSuppressNextAssistantPersist = false;
            kioskRealtimeVoiceOnlyAssistant = false;
            finishRealtimeTurnStatus();
            return;
        }
        if (assistantAnswered && functionCallsAreBackgroundQueueOnly && !backgroundQueueAllowed) {
            clearRealtimeToolFallback();
            logKioskRealtimeVoiceTrace('realtime_voice_queue_skipped', {
                summary: 'Skipped queue_bean_work because the spoken answer was complete.',
                user_content: pendingUserContent,
                assistant_text: responseAssistantText,
                reason: 'spoken_answer_complete',
            });
            functionCalls.forEach((item) => {
                sendRealtimeFunctionOutput(item.call_id, {
                    ok: true,
                    skipped: true,
                    message: 'Bean already answered this turn directly.',
                }, { createResponse: false });
            });
            persistRealtimeConversationTurn();
            kioskRealtimeAwaitingFollowup = realtimeAssistantAwaitingFollowup(responseAssistantText);
            finishRealtimeTurnStatus();
            return;
        }
        if (hasFunctionCall) {
            const preservePendingUserForDeferredQueue = functionCallsAreBackgroundQueueOnly && !responseAssistantText;
            functionCalls.forEach((item) => processRealtimeFunctionCall(item.name, item.call_id, item.arguments, {
                assistantText: responseAssistantText,
                userContent: pendingUserContent,
            }));
            if (!preservePendingUserForDeferredQueue) {
                kioskRealtimePendingUser = null;
                kioskRealtimeCurrentUserTurn = null;
            }
            return;
        }
        if (!hasFunctionCall && assistantAnswered) {
            clearRealtimeToolFallback();
            if (backgroundQueueAllowed && voiceCommandRequiresBackgroundWork(pendingUserContent)) {
                persistRealtimeConversationTurn();
                queueRealtimeFallbackWork(pendingUserContent, responseAssistantText);
                return;
            }
            persistRealtimeConversationTurn();
            kioskRealtimeAwaitingFollowup = realtimeAssistantAwaitingFollowup(responseAssistantText);
        } else if (!assistantAnswered && !kioskRealtimeToolFallbackContent && voiceCommandNeedsAgentWork(pendingUserContent)) {
            kioskRealtimePendingUser = null;
            kioskRealtimeCurrentUserTurn = null;
            queueRealtimeFallbackWork(pendingUserContent);
            return;
        } else if (!kioskRealtimeToolFallbackContent) {
            persistRealtimeConversationTurn();
        } else {
            kioskRealtimePendingUser = null;
            kioskRealtimeCurrentUserTurn = null;
        }
        finishRealtimeTurnStatus();
    }

    function mergeRealtimeFunctionCalls(calls) {
        const byKey = new Map();
        normalizeList(calls).forEach((call) => {
            if (!call?.name) return;
            const key = call.call_id || `${call.name}-${call.arguments || ''}`;
            byKey.set(key, call);
        });
        return Array.from(byKey.values());
    }

    function realtimeTextFromResponseOutput(output) {
        const strings = [];
        const visit = (value) => {
            if (strings.join(' ').length > 2000) return;
            if (typeof value === 'string') {
                const clean = value.replace(/\s+/g, ' ').trim();
                if (clean) strings.push(clean);
                return;
            }
            if (!value || typeof value !== 'object') return;
            if (typeof value.transcript === 'string') visit(value.transcript);
            if (typeof value.text === 'string') visit(value.text);
            if (typeof value.content === 'string') visit(value.content);
            if (Array.isArray(value.content)) value.content.forEach(visit);
            if (Array.isArray(value.output)) value.output.forEach(visit);
        };
        normalizeList(output).forEach(visit);
        return strings.join(' ').replace(/\s+/g, ' ').trim();
    }

    function reportKioskRealtimeUsage(payload, functionCalls = []) {
        const usage = payload?.response?.usage;
        const sessionId = kioskRealtime?.sessionId || state.session?.id;
        if (!usage || !sessionId || !state.token) return;
        const responseId = payload?.response?.id || payload?.response_id || null;
        const model = payload?.response?.model || null;
        const voiceSeconds = kioskRealtimeAssistantOutputStartedAt
            ? Math.max(1, Math.min(300, (Date.now() - kioskRealtimeAssistantOutputStartedAt) / 1000))
            : 1;
        fetchWithTimeout('/api/assistant/realtime/usage', {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                Authorization: `Bearer ${state.token}`,
            },
            body: JSON.stringify({
                session_id: sessionId,
                model,
                response_id: responseId,
                usage,
                voice_seconds: voiceSeconds,
                tool_call_count: functionCalls.length,
                action_types: ['realtime_voice', ...functionCalls.map((item) => item?.name).filter(Boolean)],
            }),
        }, 6000).then(async (response) => {
            if (response.ok) return;
            const payload = await response.json().catch(() => null);
            if (response.status === 429 && payload?.message) {
                setKioskVoiceStatus('error', payload.message);
                endKioskConversation('Say hey bean');
            }
        }).catch(() => {});
    }

    function finishRealtimeTurnStatus() {
        if (!state.kioskVoiceEnabled || !kioskRealtimeConnected()) return;
        if (realtimeAssistantOutputActive()) {
            scheduleRealtimeTurnStatusAfterOutput();
            return;
        }
        kioskRealtimeAssistantOutputStartedAt = 0;
        if (realtimeBackgroundWorkPending()) {
            showRealtimeWorkingInBackgroundWhenReady();
            return;
        }
        if (kioskConversationActive) {
            setKioskVoiceStatus('listening', 'listening');
            armKioskConversationTimeout(kioskRealtimeAwaitingFollowup ? 30000 : undefined);
        } else {
            setKioskVoiceStatus('armed', 'Say hey bean');
        }
    }

    async function processRealtimeFunctionCall(name, callId, rawArguments = '{}', options = {}) {
        clearRealtimeToolFallback();
        const callKey = callId || `${name}-${rawArguments}`;
        if (!name || kioskRealtimeProcessedCalls.has(callKey)) return;
        const quickReplyText = String(options.assistantText || kioskRealtimeAssistantDraft?.content || kioskRealtimeLastAssistantText || '').trim();
        const userContent = String(options.userContent || kioskRealtimePendingUser?.content || '').trim();
        if (name === 'queue_bean_work' && !userContent) {
            kioskRealtimeProcessedCalls.add(callKey);
            logKioskRealtimeVoiceTrace('realtime_voice_queue_skipped', {
                summary: 'Skipped queue_bean_work because there is no active user turn.',
                reason: 'missing_pending_user',
                assistant_text: quickReplyText,
                call_id: callId || null,
            });
            sendRealtimeFunctionOutput(callId, {
                ok: true,
                skipped: true,
                message: 'No active user turn is available for this background work.',
            }, { createResponse: false });
            finishRealtimeTurnStatus();
            return;
        }
        if (name === 'queue_bean_work' && !quickReplyText && options.deferForTranscript !== false) {
            window.setTimeout(() => {
                processRealtimeFunctionCall(name, callId, rawArguments, {
                    ...options,
                    deferForTranscript: false,
                    assistantText: kioskRealtimeAssistantDraft?.content || kioskRealtimeLastAssistantText || '',
                    userContent,
                });
            }, 650);
            logKioskRealtimeVoiceTrace('realtime_voice_queue_deferred', {
                summary: 'Deferred queue_bean_work until the spoken transcript is available.',
                user_content: userContent,
                call_id: callId || null,
            });
            return;
        }
        kioskRealtimeProcessedCalls.add(callKey);
        let args = {};
        try {
            args = typeof rawArguments === 'string' ? JSON.parse(rawArguments || '{}') : (rawArguments || {});
        } catch (_) {
            args = {};
        }
        if (name === 'queue_bean_work' && quickReplyText && !realtimeSpokenAnswerAllowsBackgroundQueue(userContent, quickReplyText)) {
            logKioskRealtimeVoiceTrace('realtime_voice_queue_skipped', {
                summary: 'Skipped queue_bean_work because the spoken answer was complete.',
                user_content: userContent,
                assistant_text: quickReplyText,
                reason: 'spoken_answer_complete_after_defer',
            });
            sendRealtimeFunctionOutput(callId, {
                ok: true,
                skipped: true,
                message: 'Bean already answered this turn directly.',
            }, { createResponse: false });
            persistRealtimeConversationTurn();
            kioskRealtimeAwaitingFollowup = realtimeAssistantAwaitingFollowup(quickReplyText);
            finishRealtimeTurnStatus();
            return;
        }
        if (name === 'queue_bean_work') {
            logKioskRealtimeVoiceTrace('realtime_voice_queue_started', {
                summary: 'Starting queue_bean_work.',
                user_content: userContent,
                assistant_text: quickReplyText,
                call_id: callId || null,
                arguments: args,
            });
            ensureRealtimeRequestWorkItem(userContent);
            setRealtimeBackgroundWorkActive(true, { quickReplyText, userContent });
        }
        showRealtimeWorkingInBackgroundWhenReady();
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
            sendRealtimeFunctionOutput(callId, result, {
                createResponse: name !== 'queue_bean_work' || !result?.run_id,
            });
            if (result?.run_id) {
                kioskRealtimePendingUser = null;
                kioskRealtimeCurrentUserTurn = null;
                ensureRealtimeRequestWorkItem(userContent);
                startBeanWorkEventPolling(kioskRealtime?.sessionId || state.session?.id);
                watchRealtimeAssistantRun(result.run_id, { quickReplyText, userContent });
            } else if (name === 'queue_bean_work') {
                setRealtimeBackgroundWorkActive(false);
            }
            await loadChatSessions({ resumeToday: false, shouldRender: false }).catch(() => {});
            scheduleDashboardRealtimeRefresh([{ type: 'realtime_tool_call' }]);
        } catch (error) {
            if (name === 'queue_bean_work') {
                setRealtimeBackgroundWorkActive(false);
            }
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
        if (!voiceCommandRequiresBackgroundWork(command)) return;
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

    async function queueRealtimeFallbackWork(content, quickReplyTextOverride = '') {
        if (!state.session?.id) return;
        const quickReplyText = String(quickReplyTextOverride || kioskRealtimeAssistantDraft?.content || '').trim();
        if (quickReplyText && !realtimeSpokenAnswerAllowsBackgroundQueue(content, quickReplyText)) {
            logKioskRealtimeVoiceTrace('realtime_voice_fallback_skipped', {
                summary: 'Skipped realtime fallback work because the spoken answer was complete.',
                user_content: content,
                assistant_text: quickReplyText,
                reason: 'spoken_answer_complete',
            });
            persistRealtimeConversationTurn();
            kioskRealtimeAwaitingFollowup = realtimeAssistantAwaitingFollowup(quickReplyText);
            finishRealtimeTurnStatus();
            return;
        }
        ensureRealtimeRequestWorkItem(content);
        setRealtimeBackgroundWorkActive(true, { quickReplyText, userContent: content });
        showRealtimeWorkingInBackgroundWhenReady();
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
                watchRealtimeAssistantRun(result.run_id, { quickReplyText, userContent: content });
            } else {
                setRealtimeBackgroundWorkActive(false);
            }
            await loadChatSessions({ resumeToday: false, shouldRender: false }).catch(() => {});
            scheduleDashboardRealtimeRefresh([{ type: 'realtime_tool_fallback' }]);
        } catch (error) {
            setRealtimeBackgroundWorkActive(false);
            setKioskVoiceStatus('error', friendlyError(error, 'start that background work'));
        }
    }

    function queueImmediateRealtimeBackgroundWork(content) {
        const quickReplyText = realtimeQueuedWorkAcknowledgement(content);
        logKioskRealtimeVoiceTrace('realtime_voice_immediate_queue_started', {
            summary: 'Queued app work immediately from transcript.',
            user_content: content,
            quick_reply: quickReplyText,
        });
        clearRealtimeToolFallback();
        window.clearTimeout(kioskRealtimeResponseTimer);
        kioskRealtimeResponseTimer = 0;
        kioskRealtimeCurrentUserTurn = kioskRealtimePendingUser ? { ...kioskRealtimePendingUser } : kioskRealtimeCurrentUserTurn;
        persistRealtimeConversationTurn().catch(() => {});
        ensureRealtimeRequestWorkItem(content);
        setRealtimeBackgroundWorkActive(true, { quickReplyText, userContent: content });
        setKioskVoiceStatus('working', 'working');
        recordRealtimeSpokenSegment(quickReplyText);
        markRealtimeAssistantOutputActive(2600, { started: true });
        speakKioskAcknowledgement(quickReplyText, {
            shouldPlay: () => kioskConversationActive && realtimeBackgroundWorkPending(),
        }).finally(() => {
            scheduleRealtimeTurnStatusAfterOutput();
        }).catch(() => {});
        queueRealtimeFallbackWork(content, quickReplyText);
    }

    function sendRealtimeFunctionOutput(callId, result, options = {}) {
        const dataChannel = kioskRealtime?.dataChannel;
        if (!callId || dataChannel?.readyState !== 'open') return;
        if (realtimeAssistantOutputActive() && !options.force) {
            const wait = Math.max(350, kioskRealtimeSuppressInputUntil - Date.now() + 350);
            const timer = window.setTimeout(() => {
                kioskRealtimeDeferredFunctionOutputTimers.delete(timer);
                sendRealtimeFunctionOutput(callId, result, { ...options, force: true });
            }, wait);
            kioskRealtimeDeferredFunctionOutputTimers.add(timer);
            return;
        }
        dataChannel.send(JSON.stringify({
            type: 'conversation.item.create',
            item: {
                type: 'function_call_output',
                call_id: callId,
                output: JSON.stringify(result),
            },
        }));
        if (options.createResponse !== false) {
            sendRealtimeResponseCreate();
        }
    }

    function clearDeferredRealtimeFunctionOutputs() {
        kioskRealtimeDeferredFunctionOutputTimers.forEach((timer) => window.clearTimeout(timer));
        kioskRealtimeDeferredFunctionOutputTimers.clear();
    }

    function sendRealtimeResponseCreate(options = {}) {
        const dataChannel = kioskRealtime?.dataChannel;
        if (!kioskRealtimeConnected() || dataChannel?.readyState !== 'open') return false;
        try {
            dataChannel.send(JSON.stringify({ type: 'response.create', ...options }));
            return true;
        } catch (error) {
            reportKioskRealtimeIssue('response_create_send_failed', {
                message: error?.message || '',
                data_channel_state: dataChannel?.readyState || '',
            });
            return false;
        }
    }

    function showRealtimeWorkingInBackgroundWhenReady() {
        window.clearTimeout(kioskRealtimeDeferredWorkingStatusTimer);
        if (!realtimeBackgroundWorkPending()) return;
        if (!realtimeAssistantOutputActive()) {
            setKioskVoiceStatus('working', 'working...');
            return;
        }
        const wait = Math.max(300, kioskRealtimeSuppressInputUntil - Date.now() + 250);
        kioskRealtimeDeferredWorkingStatusTimer = window.setTimeout(() => {
            kioskRealtimeDeferredWorkingStatusTimer = 0;
            if (!state.kioskVoiceEnabled || !kioskRealtimeConnected() || !kioskConversationActive) return;
            if (!realtimeBackgroundWorkPending()) return;
            if (realtimeAssistantOutputActive()) {
                showRealtimeWorkingInBackgroundWhenReady();
                return;
            }
            setKioskVoiceStatus('working', 'working...');
        }, wait);
    }

    function watchRealtimeAssistantRun(runId, context = {}, attempt = 0) {
        const id = Number(runId || 0);
        if (!id || kioskRealtimeRunWatchTimers.has(id)) return;
        if (!kioskRealtimeBackgroundWorkActive) {
            setRealtimeBackgroundWorkActive(true, context);
        } else {
            showRealtimeWorkingInBackgroundWhenReady();
        }
        const delay = attempt === 0 ? 900 : Math.min(1800 + (attempt * 450), 4500);
        const timer = window.setTimeout(async () => {
            kioskRealtimeRunWatchTimers.delete(id);
            if (!state.kioskVoiceEnabled) return;
            try {
                const run = await api(`/assistant/runs/${id}`);
                const status = String(run?.status || '').toLowerCase();
                if (['queued', 'running'].includes(status) && attempt < 45) {
                    if (!kioskRealtimeBackgroundWorkActive) {
                        setRealtimeBackgroundWorkActive(true, context);
                    } else {
                        showRealtimeWorkingInBackgroundWhenReady();
                    }
                    watchRealtimeAssistantRun(id, context, attempt + 1);
                    return;
                }
                if (status === 'completed') {
                    completeActiveBeanWorkItems();
                    handleRealtimeAssistantRunCompleted(run, context);
                    return;
                }
                if (status === 'failed') {
                    markActiveBeanWorkItems('failed');
                    setRealtimeBackgroundWorkActive(false);
                    const message = run?.error ? `I could not finish that: ${run.error}` : 'I could not finish that request.';
                    deliverRealtimeBackgroundResult(message, id);
                    return;
                }
                if (status === 'cancelled') {
                    markActiveBeanWorkItems('cancelled');
                    setRealtimeBackgroundWorkActive(false);
                    deliverRealtimeBackgroundResult('That request was cancelled.', id);
                }
            } catch (_) {
                if (attempt < 8) watchRealtimeAssistantRun(id, context, attempt + 1);
            }
        }, delay);
        kioskRealtimeRunWatchTimers.set(id, timer);
    }

    function handleRealtimeAssistantRunCompleted(run, context = {}) {
        scheduleDashboardRealtimeRefresh([{ type: 'realtime_run_completed' }]);
        refreshRealtimeDashboardContext('realtime_run_completed').catch(() => {});
        const assistantMessage = run?.assistant_message || run?.assistantMessage || null;
        const content = String(assistantMessage?.content || '').trim();
        if (!content) {
            setRealtimeBackgroundWorkActive(false);
            deliverRealtimeBackgroundResult('I finished that request.', run?.id);
            return;
        }
        const finalVoice = finalVoiceForTurn(context.userContent || '', context.quickReplyText || '', content, {});
        if (finalVoice.suppressFinal) {
            setRealtimeBackgroundWorkActive(false);
            appendPersistedAssistantMessage(assistantMessage);
            if (state.kioskVoiceEnabled && kioskRealtimeConnected() && kioskConversationActive) {
                kioskRealtimeAwaitingFollowup = realtimeAssistantAwaitingFollowup(context.quickReplyText || '');
                deliverRealtimeBackgroundResult('Done. I put the details in chat.', run?.id);
            }
            return;
        }
        appendPersistedAssistantMessage(assistantMessage);
        if (kioskRealtimeConnected()) {
            setRealtimeBackgroundWorkActive(false);
            deliverRealtimeBackgroundResult(finalVoice.text || content, run?.id);
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
        if (!kioskConversationActive) return;
        if (realtimeAssistantOutputActive()) {
            scheduleRealtimeBackgroundResultDelivery(text, runId);
            return;
        }
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
        const alreadySpoken = [...new Set([
            ...kioskRealtimeSpokenSegments,
            kioskRealtimeLastAssistantText,
        ].map((item) => String(item || '').trim()).filter(Boolean))].slice(-6);
        const voiceResult = realtimeBackgroundVoiceResult(text);
        logKioskRealtimeVoiceTrace('realtime_voice_background_result', {
            summary: 'Delivering realtime background result.',
            run_id: runId,
            result_text: text,
            voice_result: voiceResult,
            already_spoken: alreadySpoken,
        });
        if (!voiceResult) return;
        kioskRealtimeLastAssistantText = voiceResult;
        recordRealtimeSpokenSegment(voiceResult);
        markRealtimeAssistantOutputActive(realtimeAssistantOutputDurationMs(voiceResult), { started: true });
        kioskRealtimeSuppressNextAssistantPersist = true;
        kioskRealtimeVoiceOnlyAssistant = true;
        kioskRealtimeIgnoreNextFunctionCalls = true;
        dataChannel.send(JSON.stringify({
            type: 'conversation.item.create',
            item: {
                type: 'message',
                role: 'user',
                content: [{
                    type: 'input_text',
                    text: JSON.stringify({
                        realtime_background_complete: true,
                        result: text,
                        voice_result: voiceResult,
                        already_spoken: alreadySpoken,
                        instruction: 'Say voice_result exactly, unless it repeats already_spoken word-for-word. Do not repeat or paraphrase anything already spoken.',
                        rules: [
                            'Do not call tools.',
                            'Do not mention tools, models, connections, or voice.',
                            'Do not use generic filler.',
                            'Keep the spoken completion to one short sentence.',
                        ],
                    }),
                }],
            },
        }));
        sendRealtimeResponseCreate();
    }

    function realtimeBackgroundVoiceResult(text) {
        const clean = String(text || '').replace(/\s+/g, ' ').trim();
        if (!clean) return '';
        if (clean.length > 420 || /(?:^|\n)\s*(?:[-*]|\d+[.)])\s+\S/.test(text) || (String(text).match(/\n/g) || []).length >= 3) {
            return 'Done. I put the details in chat.';
        }
        return clean;
    }

    function realtimeAssistantOutputDurationMs(text) {
        const words = String(text || '').trim().split(/\s+/).filter(Boolean).length;
        return Math.min(16000, Math.max(3000, Math.round(words * 430) + 1800));
    }

    function scheduleRealtimeBackgroundResultDelivery(content, runId = null) {
        kioskRealtimePendingBackgroundResult = { content, runId };
        window.clearTimeout(kioskRealtimeBackgroundDeliveryTimer);
        const wait = Math.max(350, kioskRealtimeSuppressInputUntil - Date.now() + 350);
        kioskRealtimeBackgroundDeliveryTimer = window.setTimeout(() => {
            kioskRealtimeBackgroundDeliveryTimer = 0;
            const pending = kioskRealtimePendingBackgroundResult;
            kioskRealtimePendingBackgroundResult = null;
            if (!pending) return;
            deliverRealtimeBackgroundResult(pending.content, pending.runId);
        }, wait);
    }

    async function refreshRealtimeDashboardContext(reason = 'dashboard_context_refresh') {
        const dataChannel = kioskRealtime?.dataChannel;
        const sessionId = kioskRealtime?.sessionId || state.session?.id;
        if (!sessionId || dataChannel?.readyState !== 'open') return false;
        const context = await api(`/assistant/realtime/dashboard-context?session_id=${encodeURIComponent(sessionId)}`);
        const instructions = String(context?.instructions || '').trim();
        if (!instructions) return false;
        dataChannel.send(JSON.stringify({
            type: 'session.update',
            session: {
                type: 'realtime',
                instructions,
            },
        }));
        return true;
    }

    async function persistRealtimeConversationTurn() {
        const sessionId = kioskRealtime?.sessionId || state.session?.id;
        const userTurn = kioskRealtimePendingUser || kioskRealtimeCurrentUserTurn;
        const assistantTurn = kioskRealtimeAssistantDraft;
        const suppressAssistantPersist = kioskRealtimeSuppressNextAssistantPersist;
        kioskRealtimePendingUser = null;
        kioskRealtimeCurrentUserTurn = null;
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
            setKioskVoiceStatus('error', 'Bean needs a moment');
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
        kioskRealtimeSpokenSegments.length = 0;
        kioskRealtimeLastAssistantText = '';
        kioskRealtimeLastAssistantOutputEndedAt = 0;
        window.clearTimeout(kioskConversationTimer);
    }

    function armKioskConversationTimeout(timeoutMs = 15000) {
        window.clearTimeout(kioskConversationTimer);
        if (!kioskConversationActive || !state.kioskVoiceEnabled) return;
        if (kioskRealtimeBackgroundWorkActive) return;
        kioskConversationTimer = window.setTimeout(() => {
            kioskConversationTimer = 0;
            logKioskRealtimeVoiceTrace('realtime_voice_conversation_timeout', {
                summary: 'Realtime follow-up window timed out.',
                awaiting_followup: kioskRealtimeAwaitingFollowup,
                background_active: realtimeBackgroundWorkPending(),
                assistant_output_active: realtimeAssistantOutputActive(),
                pending_user_present: Boolean(kioskRealtimePendingUser?.content),
                current_user_turn_present: Boolean(kioskRealtimeCurrentUserTurn?.content),
            });
            endKioskConversation();
        }, timeoutMs);
    }

    function endKioskConversation(message = '') {
        window.clearTimeout(kioskConversationTimer);
        window.clearTimeout(kioskCommandTimer);
        window.clearTimeout(kioskHeardTimer);
        window.clearTimeout(kioskBridgeTimer);
        window.clearTimeout(kioskRealtimeResponseTimer);
        kioskConversationTimer = 0;
        kioskCommandTimer = 0;
        kioskHeardTimer = 0;
        kioskBridgeTimer = 0;
        kioskRealtimeResponseTimer = 0;
        clearRealtimeToolFallback();
        kioskConversationActive = false;
        kioskRealtimePendingUser = null;
        kioskRealtimeCurrentUserTurn = null;
        kioskRealtimeAssistantDraft = null;
        kioskRealtimeSuppressNextAssistantPersist = false;
        kioskRealtimeVoiceOnlyAssistant = false;
        kioskRealtimeIgnoreNextFunctionCalls = false;
        setRealtimeBackgroundWorkActive(false);
        kioskRealtimeAwaitingFollowup = false;
        kioskRealtimeLastAssistantText = '';
        kioskRealtimeLastAssistantOutputEndedAt = 0;
        kioskRealtimeWakeContinuationUntil = 0;
        kioskRealtimeResponseCreateSentAt = 0;
        kioskRealtimeAwaitingFirstAudio = false;
        kioskRealtimeSpokenSegments.length = 0;
        clearRealtimeAssistantOutputGuard();
        kioskRealtimeUserTranscriptDrafts.clear();
        kioskQuickReplyGeneration += 1;
        kioskCommandText = '';
        setKioskVoiceStatus('armed', message || 'Say hey bean');
    }

    function cancelKioskVoiceCapture() {
        kioskIntentionalCancelActive = true;
        stopKioskBargeInListening();
        stopKioskSpeechPlayback();
        if (
            kioskRealtime?.dataChannel?.readyState === 'open'
            && (kioskRealtimeAwaitingFirstAudio || realtimeAssistantOutputActive() || ['responding', 'speaking'].includes(state.kioskVoicePhase))
        ) {
            try { kioskRealtime.dataChannel.send(JSON.stringify({ type: 'response.cancel' })); } catch (_) {}
        }
        logKioskRealtimeVoiceTrace('realtime_voice_cancel_requested', {
            summary: 'User cancelled the active voice turn.',
            phase: state.kioskVoicePhase || '',
        });
        pauseKioskVoiceListening();
        endKioskConversation('Cancelled');
        if (state.busy) {
            cancelBeanTurn(null, { preserveKioskStatus: true });
            return;
        }
        restartKioskVoiceListeningSoon(650);
        window.setTimeout(() => {
            kioskIntentionalCancelActive = false;
        }, 1500);
    }

    function showKioskHeardTranscript(transcript, options = {}) {
        window.clearTimeout(kioskHeardTimer);
        kioskHeardTimer = 0;
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
        const turnContract = kioskVoiceTurnContract(quickReply, quickReplyText, likelyNeedsAgentWork, wantsDetailedChat);
        const shouldContinueAgent = turnContract.shouldContinueAgent;
        const allowLateQuickReply = !quickReplyText && likelyNeedsAgentWork;
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
                voiceQuickReplyMode: turnContract.quickReplyMode,
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
                if (kioskIntentionalCancelActive) {
                    kioskIntentionalCancelActive = false;
                    setKioskVoiceStatus('armed', 'Cancelled');
                    restartKioskVoiceListeningSoon(650);
                    return;
                }
                setKioskVoiceStatus('error', 'no response');
                await sleep(1200);
            } else {
                const finalVoice = finalVoiceForTurn(content, quickReplyText, assistantContent, {
                    wantsDetailedChat,
                    quickReplyMode: turnContract.quickReplyMode,
                });
                if (finalVoice.suppressFinal) {
                    removeLatestAssistantMessageIfDuplicate(assistantContent, quickReplyText);
                }
                const spoken = finalVoice.text
                    ? await speakKioskResponse(finalVoice.text, finalVoice.handoff ? {} : { pendingMessage: 'working...' })
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

    function pauseBargeInDuringSpeech() {
        const shouldResume = Boolean(kioskBargeRecognition || kioskBargeRecognitionActive || kioskBargeRestartTimer);
        stopKioskBargeInListening();
        return () => {
            if (!shouldResume || !kioskConversationActive || !state.kioskVoiceEnabled) return;
            if (!state.busy && !['working', 'responding'].includes(state.kioskVoicePhase)) return;
            restartKioskBargeInListeningSoon(900);
        };
    }

    async function speakWithBargeInPaused(playSpeech) {
        const resumeBargeIn = pauseBargeInDuringSpeech();
        try {
            return await playSpeech();
        } finally {
            resumeBargeIn();
        }
    }

    function speakKioskVoiceSegment(text, generation, spokenSegments) {
        const cleanText = String(text || '').trim();
        if (!cleanText) return Promise.resolve(false);
        spokenSegments.push(cleanText);
        return speakWithBargeInPaused(() => speakKioskAcknowledgement(text, {
            shouldPlay: () => kioskConversationActive && generation === kioskQuickReplyGeneration,
        }));
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

    function kioskVoiceTurnContract(quickReply, quickReplyText, likelyNeedsAgentWork, wantsDetailedChat) {
        const explicitContract = String(quickReply?.responseContract || '').toLowerCase();
        const hasQuickReply = String(quickReplyText || '').trim() !== '';
        if (wantsDetailedChat) {
            return { shouldContinueAgent: true, quickReplyMode: hasQuickReply ? 'summary_then_detail' : 'pending_detail' };
        }
        if (explicitContract === 'complete' || (hasQuickReply && quickReply?.continueAgent === false && !likelyNeedsAgentWork)) {
            return { shouldContinueAgent: false, quickReplyMode: 'complete' };
        }
        if (['acknowledged_background', 'pending_background', 'background'].includes(explicitContract)) {
            return { shouldContinueAgent: true, quickReplyMode: hasQuickReply ? 'acknowledged_background' : 'pending_background' };
        }
        if (likelyNeedsAgentWork) {
            return { shouldContinueAgent: true, quickReplyMode: hasQuickReply ? 'acknowledged_background' : 'pending_background' };
        }
        if (hasQuickReply) {
            return { shouldContinueAgent: false, quickReplyMode: 'complete' };
        }
        return { shouldContinueAgent: true, quickReplyMode: 'direct_answer' };
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

    function normalizeKioskVoiceStatus(phase, message) {
        const text = String(message || '').trim();
        if (phase === 'armed') return { phase, message: text || 'Say hey bean' };
        if (phase === 'heard') return { phase, message: text || 'Heard' };
        if (phase === 'listening') return { phase, message: text === 'Go ahead' ? 'Go ahead' : 'Listening' };
        if (phase === 'working') {
            if (/thinking/i.test(text)) return { phase, message: 'Thinking' };
            if (/waking|connect/i.test(text)) return { phase, message: 'Connecting' };
            return { phase, message: 'Working' };
        }
        if (phase === 'responding' || phase === 'speaking') return { phase, message: 'Speaking' };
        if (phase === 'idle') return { phase, message: text };
        return { phase, message: text || phase };
    }

    function setKioskVoiceStatus(phase, message) {
        const normalized = normalizeKioskVoiceStatus(phase, message);
        state.kioskVoicePhase = normalized.phase;
        state.kioskVoiceMessage = normalized.message;
        if (state.phase === 'signedIn' && !updateKioskVoicePillsInPlace()) {
            render();
        }
    }

    function updateKioskVoicePillsInPlace() {
        const shells = mount.querySelectorAll('.hb-kiosk-voice-status-shell');
        if (!shells.length) return false;
        const model = kioskVoiceStatusTagModel({ topbar: true, workStatus: true });
        shells.forEach((shell) => {
            const pill = shell.querySelector('[data-toggle-kiosk-voice]');
            if (!pill) return;
            shell.classList.toggle('hb-kiosk-voice-status-shell-working', model.workActive);
            Array.from(pill.classList)
                .filter((className) => className.startsWith('hb-kiosk-voice-pill-') && !['hb-kiosk-voice-pill-button', 'hb-kiosk-voice-pill-cancelable', 'hb-kiosk-voice-pill-standalone', 'hb-kiosk-voice-pill-topbar'].includes(className))
                .forEach((className) => pill.classList.remove(className));
            pill.classList.add(`hb-kiosk-voice-pill-${model.phase}`);
            pill.classList.toggle('hb-kiosk-voice-pill-cancelable', model.cancelable);
            pill.setAttribute('aria-label', model.actionLabel);
            pill.setAttribute('title', model.actionLabel);
            pill.setAttribute('aria-pressed', model.ready ? 'true' : 'false');
            const labelNode = pill.querySelector('.hb-kiosk-voice-pill-label');
            if (labelNode) labelNode.textContent = model.label;
            const countNode = pill.querySelector('.hb-kiosk-voice-work-count');
            if (model.workItems.length) {
                const countText = `${model.completedCount}/${model.workItems.length}`;
                if (countNode) {
                    countNode.textContent = countText;
                } else {
                    pill.insertAdjacentHTML('beforeend', `<span class="hb-kiosk-voice-work-count">${escapeHtml(countText)}</span>`);
                }
            } else {
                countNode?.remove();
            }
            updateBeanWorkListInPlace(shell, model.workItems);
        });
        return true;
    }

    function updateBeanWorkListInPlace(shell, items) {
        const pill = shell.querySelector('[data-toggle-kiosk-voice]');
        let list = shell.querySelector('.hb-kiosk-voice-work-list');
        if (!list) {
            pill?.insertAdjacentHTML('afterend', beanWorkListMarkup([], 'hb-bean-work-list hb-kiosk-voice-work-list hb-kiosk-voice-work-list-empty'));
            list = shell.querySelector('.hb-kiosk-voice-work-list');
        }
        if (!list) return;
        list.classList.toggle('hb-kiosk-voice-work-list-empty', items.length === 0);
        if (items.length) {
            list.removeAttribute('aria-hidden');
        } else {
            list.setAttribute('aria-hidden', 'true');
        }
        const existing = new Map(Array.from(list.querySelectorAll('[data-bean-work-id]')).map((node) => [node.dataset.beanWorkId || '', node]));
        items.forEach((item, index) => {
            const id = String(item.id || `work-${index}`);
            let row = existing.get(id);
            if (!row) {
                row = createBeanWorkItemNode(item);
            }
            updateBeanWorkItemNode(row, item);
            const current = list.children[index] || null;
            if (row !== current) list.insertBefore(row, current);
            existing.delete(id);
        });
        existing.forEach((row) => row.remove());
    }

    function createBeanWorkItemNode(item) {
        const template = document.createElement('template');
        template.innerHTML = beanWorkItemMarkup(item).trim();
        return template.content.firstElementChild;
    }

    function updateBeanWorkItemNode(row, item) {
        const done = beanWorkItemDone(item);
        row.dataset.beanWorkId = String(item.id || '');
        row.classList.toggle('hb-bean-work-item-done', done);
        const checkbox = row.querySelector('[data-bean-work-checkbox]');
        if (checkbox) checkbox.innerHTML = done ? icons.checkCircle : '';
        const label = row.querySelector('[data-bean-work-label]');
        if (label && label.textContent !== String(item.label || 'Bean work item')) {
            label.textContent = String(item.label || 'Bean work item');
        }
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
                responseContract: String(response?.response_contract || response?.responseContract || '').trim(),
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
                    message: kioskLastTtsError || 'Bean needs a moment',
                });
                if (allowDebugBrowserVoiceFallback()) return speakBrowserTts(text);
                setKioskVoiceStatus('error', 'Bean needs a moment');
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
        return speakWithBargeInPaused(() => {
            if (profileTtsProvider() === 'openai') {
                return playOpenAiTts(text, { ...options, quietFailure: true }).then((spoken) => {
                    if (spoken) return true;
                    reportKioskRealtimeIssue('openai_tts_emergency_fallback_failure', {
                        message: kioskLastTtsError || 'Bean needs a moment',
                    });
                    if (allowDebugBrowserVoiceFallback()) return speakBrowserTts(text);
                    setKioskVoiceStatus('error', 'Bean needs a moment');
                    return false;
                });
            }
            return allowDebugBrowserVoiceFallback() ? speakBrowserTts(text) : Promise.resolve(false);
        });
    }

    function finalVoiceForTurn(userContent, quickReplyText, assistantContent, options = {}) {
        const text = speechTextFromAssistant(assistantContent);
        if (!text) return { text: '', handoff: false, suppressFinal: false };
        if (!quickReplyText) return { text, handoff: false, suppressFinal: false };
        if (quickReplyCoversFinal(quickReplyText, text)) {
            return { text: '', handoff: false, suppressFinal: true };
        }
        const continuation = finalContinuationAfterQuickReply(quickReplyText, text);
        if (!continuation) {
            return { text: '', handoff: false, suppressFinal: true };
        }
        if (options.wantsDetailedChat || finalResponseIsDetailed(assistantContent, text)) {
            return { text: finalDetailNotice(userContent), handoff: true, suppressFinal: false };
        }
        return { text: continuation, handoff: false, suppressFinal: false };
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
        if (!quick || !final) return false;
        if (final.startsWith(quick.slice(0, Math.min(quick.length, 100)))) {
            return final.length <= quick.length + 100 || novelContentRatio(final, quick) < 0.18;
        }
        if (quick.length >= 24 && final.length <= quick.length + 180 && quickSimilarity(quick, final) > 0.58 && novelContentRatio(final, quick) < 0.32) return true;
        return quick.length >= 40 && quickSimilarity(quick, final) > 0.68 && novelContentRatio(final, quick) < 0.24;
    }

    function finalContinuationAfterQuickReply(quickReplyText, finalText) {
        const quick = normalizeComparableSpeech(quickReplyText);
        const final = normalizeComparableSpeech(finalText);
        if (!quick || !final) return String(finalText || '').trim();
        if (final.startsWith(quick)) {
            return String(finalText || '').trim().slice(String(quickReplyText || '').trim().length).replace(/^[\s,.;:-]+/, '').trim();
        }

        const sentences = String(finalText || '')
            .replace(/\s+/g, ' ')
            .trim()
            .match(/[^.!?]+[.!?]+|[^.!?]+$/g) || [];
        const kept = [];
        sentences.forEach((sentence) => {
            const cleaned = stripQuickReplyOverlap(sentence, quick);
            if (!cleaned) return;
            kept.push(cleaned);
        });
        const continuation = kept.join(' ').trim();
        if (!continuation) return '';
        const continuationNormalized = normalizeComparableSpeech(continuation);
        if (continuationNormalized && quickSimilarity(quick, continuationNormalized) > 0.74 && novelContentRatio(continuationNormalized, quick) < 0.28) return '';
        return continuation;
    }

    function stripQuickReplyOverlap(sentence, normalizedQuickReply) {
        const original = String(sentence || '').replace(/\s+/g, ' ').trim();
        const normalizedSentence = normalizeComparableSpeech(original);
        if (!original || !normalizedSentence || !normalizedQuickReply) return original;
        if (normalizedQuickReply.includes(normalizedSentence)) return '';
        const similarity = quickSimilarity(normalizedQuickReply, normalizedSentence);
        const novelty = novelContentRatio(normalizedSentence, normalizedQuickReply);
        if (similarity > 0.58 && novelty < 0.34) return '';
        if (similarity > 0.72 && normalizedSentence.split(' ').length <= 14) return '';

        const clauses = original
            .split(/(?<=,|;|:)\s+|\s+(?:and|then)\s+/i)
            .map((part) => part.trim())
            .filter(Boolean);
        if (clauses.length <= 1) return original;

        const keptClauses = clauses.filter((clause) => {
            const normalizedClause = normalizeComparableSpeech(clause);
            if (!normalizedClause) return false;
            if (normalizedQuickReply.includes(normalizedClause)) return false;
            return !(quickSimilarity(normalizedQuickReply, normalizedClause) > 0.6 && novelContentRatio(normalizedClause, normalizedQuickReply) < 0.3);
        });

        return keptClauses.join(', ').replace(/^[\s,.;:-]+/, '').trim();
    }

    function removeLatestAssistantMessageIfDuplicate(assistantContent, quickReplyText) {
        if (!quickReplyCoversFinal(quickReplyText, speechTextFromAssistant(assistantContent))) return false;
        const index = [...state.messages].reverse().findIndex((message) => {
            return message?.role === 'assistant'
                && normalizeComparableSpeech(message.content) === normalizeComparableSpeech(assistantContent);
        });
        if (index < 0) return false;
        const actualIndex = state.messages.length - 1 - index;
        state.messages.splice(actualIndex, 1);
        render();
        scrollChatToBottom();
        return true;
    }

    function normalizeComparableSpeech(value) {
        return String(value || '')
            .toLowerCase()
            .replace(/[^a-z0-9\s]/g, ' ')
            .replace(/\s+/g, ' ')
            .trim();
    }

    function quickSimilarity(a, b) {
        const aWords = new Set(comparableContentWords(a));
        const bWords = new Set(comparableContentWords(b));
        if (!aWords.size || !bWords.size) return 0;
        let overlap = 0;
        aWords.forEach((word) => {
            if (bWords.has(word)) overlap += 1;
        });
        return overlap / Math.min(aWords.size, bWords.size);
    }

    function novelContentRatio(candidate, reference) {
        const candidateWords = comparableContentWords(candidate);
        if (!candidateWords.length) return 0;
        const referenceWords = new Set(comparableContentWords(reference));
        const novelCount = candidateWords.filter((word) => !referenceWords.has(word)).length;
        return novelCount / candidateWords.length;
    }

    function comparableContentWords(value) {
        const stopWords = new Set([
            'about', 'after', 'again', 'also', 'and', 'are', 'bean', 'been', 'being', 'can', 'could',
            'for', 'from', 'get', 'got', 'have', 'here', 'into', 'just', 'like', 'now', 'okay',
            'one', 'out', 'right', 'sure', 'that', 'the', 'then', 'there', 'this', 'with', 'you',
            'your', 'youre', 'ill', 'i ll', 'ive', 'i ve', 'its', 'it s',
        ]);
        return normalizeComparableSpeech(value)
            .split(' ')
            .map((word) => word.replace(/'s$/, ''))
            .filter((word) => word.length > 2 && !stopWords.has(word));
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
                setKioskVoiceStatus(options.status || 'responding', 'Bean is answering');
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
                rememberOpenAiTtsError(payload?.message || 'Bean needs a moment', !options.quietFailure);
                return false;
            }
            const audioBuffer = await response.arrayBuffer();
            if (!audioBuffer.byteLength) {
                rememberOpenAiTtsError('Bean needs a moment', !options.quietFailure);
                return false;
            }
            if (options.shouldPlay && !options.shouldPlay()) return false;
            const onStart = () => setKioskVoiceStatus(options.status || 'responding', 'Bean is answering');
            if (await playOpenAiAudioBuffer(audioBuffer, { onStart })) return true;
            if (options.shouldPlay && !options.shouldPlay()) return false;
            return playAudioBlobFallback(audioBuffer, response.headers.get('Content-Type') || 'audio/wav', { onStart });
        } catch (error) {
            const message = error?.message === 'audio_not_unlocked' || error?.name === 'NotAllowedError'
                ? 'Bean needs one click'
                : `Bean had trouble responding${error?.name ? `: ${error.name}` : ''}`;
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
        return String(message || 'Bean needs a moment')
            .replace(/OpenAI text-to-speech/gi, 'Bean')
            .replace(/OpenAI voice/gi, 'Bean')
            .replace(/OpenAI/gi, 'Bean')
            .replace(/API key/gi, 'key');
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
        return 'Bean is paused';
    }

    async function toggleKioskVoiceMode() {
        if (state.kioskVoiceEnabled && kioskRealtimeConnected() && kioskVoicePillIsCancelable()) {
            cancelKioskVoiceCapture();
            return;
        }
        if (state.kioskVoiceEnabled && !kioskRealtimeConnected()) {
            clearKioskRealtimeReconnect();
            kioskRealtimeReconnectAttempts = 0;
            setKioskVoiceStatus('working', 'Bean is waking up');
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
            state.kioskVoiceMessage = 'Connecting';
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
            const workspaceId = currentWorkspaceId();
            const [summary, tasks, pastTasks, reminders, calendar, categories, googleStatus] = await Promise.all([
                api(workspaceScopedPath('/today', workspaceId)),
                api(workspaceScopedPath('/tasks', workspaceId)),
                api(workspaceScopedPath('/tasks/past', workspaceId)),
                api(workspaceScopedPath('/reminders', workspaceId)),
                api(workspaceScopedPath(calendarPath, workspaceId)),
                api(workspaceScopedPath('/event-categories', workspaceId)),
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
            setActiveWorkspaceLocally(workspaceId, { persist: false });
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
        api(workspaceScopedPath('/calendar-events'))
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

    function refreshCalendarAfterEventSave() {
        const generation = ++dashboardRefreshGeneration;
        api(workspaceScopedPath('/calendar-events?skip_google_sync=1'))
            .then((calendar) => {
                if (generation !== dashboardRefreshGeneration) return;
                state.calendar = reconcileCalendarRefresh(calendar);
                saveDashboardCache();
                renderDashboardDataUpdate();
            })
            .catch(() => {
                // The saved event is already rendered optimistically; manual refresh can surface any later issue.
            });
    }

    async function loadAdminUsage(force = false) {
        if (!userIsAdmin() || (state.adminUsage && !force)) return;
        state.adminUsageLoading = true;
        state.error = '';
        render();
        try {
            const growthRange = encodeURIComponent(state.adminUserGrowthRange || 'last_30_days');
            const [usage, modelRegistry, hermesStatus, planLimits] = await Promise.all([
                api(`/admin/usage/summary?user_growth_range=${growthRange}`),
                api('/admin/settings/models'),
                api('/admin/hermes/status').catch((error) => ({
                    configured: false,
                    version: 'Unavailable',
                    error: friendlyError(error, 'check Hermes status'),
                })),
                api('/admin/plan-limits'),
            ]);
            state.adminUsage = usage;
            state.adminModelRegistry = modelRegistry;
            state.adminHermesStatus = hermesStatus;
            state.adminPlanLimits = planLimits;
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
                api(workspaceScopedPath('/calendar-events')),
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
        const requested = new Date(month.getFullYear(), month.getMonth(), Math.min(selected.getDate(), daysInTargetMonth));
        const allowed = allowedCalendarDate(requested);
        if (allowed.blocked) showCalendarHistoryLimit();
        state.selectedDay = dateOnly(allowed.date);
        resetCalendarWindow(allowed.date);
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

    async function updateHomeCityPreference(event) {
        event.preventDefault();
        const form = event.currentTarget;
        const homeCity = String(new FormData(form).get('homeCity') || '').trim();
        await saveHomeCityPreference(homeCity);
    }

    async function clearHomeCityPreference(event) {
        event.preventDefault();
        await saveHomeCityPreference('');
    }

    async function saveHomeCityPreference(homeCity) {
        try {
            state.user = await api('/auth/me', {
                method: 'PATCH',
                body: {
                    home_city: homeCity || null,
                    workspace_id: currentWorkspaceId() || null,
                },
            });
            state.notice = homeCity ? 'Home city saved.' : 'Home city cleared.';
            state.error = '';
            syncSummaryAgentProfileFromUser();
            render();
            refreshRealtimeDashboardContext('home_city_updated').catch(() => {});
        } catch (error) {
            state.error = friendlyError(error, 'save the home city');
            render();
        }
    }

    async function updateThemePreference(event) {
        const theme = normalizeThemeKey(event.currentTarget.dataset.themeOption || event.currentTarget.value);
        if (theme === currentThemeKey()) return;
        const previousUser = state.user;
        state.user = { ...(state.user || {}), theme };
        state.error = '';
        state.notice = '';
        render();
        try {
            state.user = await api('/auth/me', { method: 'PATCH', body: { theme } });
            state.notice = 'Theme saved.';
            render();
        } catch (error) {
            state.user = previousUser;
            state.error = friendlyError(error, 'save theme');
            render();
        }
    }

    function applyBillingReturnNotice() {
        const status = String(state.billingCheckoutStatus || '').toLowerCase();
        if (!status) return;
        if (status === 'payment_success') {
            state.notice ||= 'Payment method update received. Refresh billing if the card summary does not appear yet.';
        } else if (status === 'payment_cancel') {
            state.notice ||= 'Payment method update canceled. No payment details were changed.';
        } else if (status === 'plan_success') {
            state.notice ||= 'Subscription checkout completed. Refresh billing if the plan summary does not appear yet.';
        } else if (status === 'plan_cancel') {
            state.notice ||= 'Subscription checkout canceled. No plan change was made.';
        }
        state.billingCheckoutStatus = '';
        if (window.location.pathname === '/app') {
            history.replaceState({}, '', '/app');
        }
    }

    async function refreshBillingSettings({ user = false, force = false, message = 'Billing refreshed.' } = {}) {
        if (!force && (state.billingBusy || state.billingPaymentLoading)) return;
        state.billingPaymentLoading = true;
        state.error = '';
        state.billingError = '';
        render();
        try {
            const requests = [
                api('/billing/subscription'),
                api('/billing/payment-method'),
            ];
            if (user) requests.push(api('/auth/me'));
            const [subscription, payment, freshUser] = await Promise.all(requests);
            state.subscriptionSummary = subscription;
            state.billingPaymentMethod = payment?.payment_method || payment?.paymentMethod || null;
            if (freshUser) state.user = freshUser;
            state.billingMessage = message;
        } catch (error) {
            state.billingError = friendlyError(error, 'refresh billing');
        } finally {
            state.billingPaymentLoading = false;
            render();
        }
    }

    async function changeBillingPlan() {
        if (state.billingBusy) return;
        const plan = mount.querySelector('[data-billing-plan-select]')?.value || '';
        if (!subscriptionPlans[plan]) return;
        const currentPlan = String(state.subscriptionSummary?.tier || state.user?.subscription_tier || state.user?.subscriptionTier || 'base').toLowerCase();
        if (plan === currentPlan) {
            state.notice = 'That plan is already active.';
            state.error = '';
            render();
            return;
        }
        state.billingBusy = true;
        state.error = '';
        state.billingError = '';
        state.billingMessage = '';
        state.notice = '';
        render();
        try {
            const result = await api('/billing/subscription/change-plan', {
                method: 'POST',
                body: { plan },
            });
            if (result?.url) {
                window.location.href = result.url;
                return;
            }
            state.subscriptionSummary = result?.subscription || state.subscriptionSummary;
            const freshUser = await api('/auth/me').catch(() => null);
            if (freshUser) state.user = freshUser;
            state.billingMessage = `Plan changed to ${subscriptionPlans[plan].label}.`;
        } catch (error) {
            state.billingError = friendlyError(error, 'change your subscription');
        } finally {
            state.billingBusy = false;
            render();
        }
    }

    async function startBillingPaymentUpdate() {
        if (state.billingBusy) return;
        state.billingBusy = true;
        state.error = '';
        state.billingError = '';
        state.billingMessage = '';
        state.notice = '';
        render();
        try {
            const checkout = await api('/billing/payment-method/checkout-session', { method: 'POST' });
            if (!checkout?.url) throw new Error('Stripe did not return a payment update page.');
            window.location.href = checkout.url;
        } catch (error) {
            state.billingBusy = false;
            state.billingError = friendlyError(error, 'update your payment method');
            render();
        }
    }

    async function cancelBillingRenewal() {
        if (state.billingBusy) return;
        if (!confirm('Cancel subscription renewal? Your current access stays active until the end of the paid period or trial.')) return;
        state.billingBusy = true;
        state.error = '';
        state.billingError = '';
        state.billingMessage = 'Canceling renewal...';
        state.notice = '';
        render();
        try {
            const result = await api('/billing/subscription/cancel', { method: 'POST' });
            state.subscriptionSummary = result?.subscription || state.subscriptionSummary;
            const freshUser = await api('/auth/me').catch(() => null);
            if (freshUser) state.user = freshUser;
            await refreshBillingSettings({
                user: true,
                force: true,
                message: 'Subscription renewal canceled. Current access stays active through the end of this period.',
            });
        } catch (error) {
            state.billingError = friendlyError(error, 'cancel your subscription');
        } finally {
            state.billingBusy = false;
            render();
        }
    }

    async function resumeBillingSubscription() {
        if (state.billingBusy) return;
        state.billingBusy = true;
        state.error = '';
        state.billingError = '';
        state.billingMessage = 'Restarting subscription...';
        state.notice = '';
        render();
        try {
            const result = await api('/billing/subscription/resume', { method: 'POST' });
            state.subscriptionSummary = result?.subscription || state.subscriptionSummary;
            const freshUser = await api('/auth/me').catch(() => null);
            if (freshUser) state.user = freshUser;
            await refreshBillingSettings({
                user: true,
                force: true,
                message: 'Subscription restarted. Renewal is active again.',
            });
        } catch (error) {
            state.billingError = friendlyError(error, 'restart your subscription');
        } finally {
            state.billingBusy = false;
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
            await refreshOnly(false, { skipCalendarSync: true });
            if (state.error) {
                throw new Error(state.error);
            }
            state.notice = `Switched to ${workspaceDisplayName(workspace)}.`;
            renderDashboardDataUpdate({ deferIfEditing: true });
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

    async function saveSettingsCategory(event) {
        event.preventDefault();
        const form = event.currentTarget;
        const categoryId = form.dataset.settingsCategoryForm;
        if (!categoryId) return;
        const data = Object.fromEntries(new FormData(form).entries());
        try {
            const category = await api(`/event-categories/${categoryId}`, { method: 'PATCH', body: { name: data.name, color: data.color } });
            state.settingsCategoryId = String(category?.id || categoryId);
            state.notice = 'Category saved.';
            await refreshOnly(false);
        } catch (error) {
            state.error = friendlyError(error, 'save category');
        }
        render();
    }

    async function deleteSettingsCategory(id) {
        if (!id || !confirm('Delete this category from items?')) return;
        try {
            await api(`/event-categories/${id}`, { method: 'DELETE' });
            state.settingsCategoryId = '';
            state.notice = 'Category deleted.';
            await refreshOnly(false);
        } catch (error) {
            state.error = friendlyError(error, 'delete category');
        }
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
        return state.user?.active_workspace?.id || state.user?.activeWorkspace?.id || state.summary?.workspace?.id || state.summary?.workspaceId || workspaces().find((workspace) => workspace.active || workspace.is_default || workspace.isDefault)?.id || workspaces()[0]?.id || '';
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

    function itemColor(item = {}) {
        const category = String(item?.category || '').trim();
        if (!category) return themeAccentColor();
        return safeColor(item?.color || categoryColor(category));
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
        const hourHeight = timelineHourHeight();
        return {
            minutes: Math.round(durationMinutes),
            css: `top:${(minutesFromStart / 60) * hourHeight}px;height:${(durationMinutes / 60) * hourHeight}px`,
        };
    }

    function timelineHourHeight() {
        return window.matchMedia?.('(max-width: 700px)').matches ? 64 : 88;
    }

    function timelineGutterWidth() {
        return window.matchMedia?.('(max-width: 700px)').matches ? 56 : 74;
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

    function currentPlanLimits() {
        return state.user?.plan_limits || state.user?.planLimits || {};
    }

    function calendarHistoryCutoffDate() {
        const cutoff = currentPlanLimits().history_cutoff || currentPlanLimits().historyCutoff;
        if (!cutoff) return null;
        return parseLocalDate(String(cutoff).slice(0, 10));
    }

    function calendarHistoryLimitMessage() {
        const days = currentPlanLimits().history_days ?? currentPlanLimits().historyDays;
        const parsedDays = Number(days);
        if (Number.isFinite(parsedDays) && parsedDays > 0) {
            return `Your current plan includes ${parsedDays} days of calendar history.`;
        }
        return 'Your current plan has limited calendar history access.';
    }

    function showCalendarHistoryLimit() {
        state.notice = '';
        state.error = calendarHistoryLimitMessage();
    }

    function clampCalendarDate(date) {
        const requested = parseLocalDate(date);
        const cutoff = calendarHistoryCutoffDate();
        return cutoff && requested < cutoff ? cutoff : requested;
    }

    function calendarDateAllowed(date) {
        const requested = parseLocalDate(date);
        const cutoff = calendarHistoryCutoffDate();
        return !cutoff || requested >= cutoff;
    }

    function allowedCalendarDate(date) {
        const requested = parseLocalDate(date);
        const allowed = clampCalendarDate(requested);
        return {
            date: allowed,
            blocked: !sameDate(requested, allowed),
        };
    }

    function visibleCalendarDays(start) {
        ensureCalendarWindowCovers(start);
        const firstVisible = parseLocalDate(state.calendarWindowStart);
        const dayCount = Math.max(calendarInitialWindowDays, Number(state.calendarWindowDayCount || calendarInitialWindowDays));
        return Array.from({ length: dayCount }, (_, index) => addDays(firstVisible, index))
            .filter((day) => calendarDateAllowed(day));
    }

    function initialCalendarWindowStart(date) {
        const rawStart = addDays(weekDays(parseLocalDate(date))[0], -14);
        try {
            return dateOnly(clampCalendarDate(rawStart));
        } catch (_) {
            return dateOnly(rawStart);
        }
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
        const cutoff = calendarHistoryCutoffDate();
        if (cutoff && start < cutoff) {
            dayCount = Math.max(dayCount - Math.ceil((cutoff - start) / 86400000), calendarInitialWindowDays);
            start = cutoff;
            changed = true;
        }

        while (selected < addDays(start, 14)) {
            if (cutoff && start <= cutoff) break;
            start = addDays(start, -calendarWindowChunkDays);
            if (cutoff && start < cutoff) start = cutoff;
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
        return Math.max(150, Math.floor((estimatedTimelineWidth - timelineGutterWidth()) / visibleDayCount));
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
        if (sameDate(parsed, addDays(new Date(), 1))) return `Tomorrow, ${weekdayShort(parsed)}`;
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

    function formatDateOnly(value) {
        if (!value) return '';
        return new Date(value).toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
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

    function formatCompactNumber(value) {
        const number = Number(value || 0);
        if (number >= 1000000) return `${(number / 1000000).toFixed(number >= 10000000 ? 0 : 1)}M`;
        if (number >= 1000) return `${(number / 1000).toFixed(number >= 10000 ? 0 : 1)}K`;
        return number.toLocaleString();
    }

    function formatTime(value) {
        if (!value) return '';
        return new Date(value).toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' });
    }

    function formatTopbarTime(value) {
        if (!value) return '';
        return new Date(value)
            .toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' })
            .replace(/\s/g, '')
            .toLowerCase();
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

    function safeColor(value, fallback = themeAccentColor()) {
        return /^#[0-9a-f]{6}$/i.test(value || '') ? value : fallback;
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
            timeline.scrollLeft = Math.max(0, selected.offsetLeft - timelineGutterWidth());
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
        const cutoff = calendarHistoryCutoffDate();
        if (shouldPrepend && cutoff && start <= cutoff) {
            showCalendarHistoryLimit();
            render();
            return false;
        }
        const nextStart = shouldPrepend ? clampCalendarDate(addDays(start, -calendarWindowChunkDays)) : start;
        const addedPreviousDays = shouldPrepend ? Math.max(0, Math.round((start - nextStart) / 86400000)) : 0;
        state.calendarWindowStart = dateOnly(nextStart);
        state.calendarWindowDayCount = Math.max(calendarInitialWindowDays, Number(state.calendarWindowDayCount || calendarInitialWindowDays))
            + addedPreviousDays
            + (shouldAppend ? calendarWindowChunkDays : 0);
        state.timelineScrollRestore = {
            left: timeline.scrollLeft + (shouldPrepend ? dayWidth * addedPreviousDays : 0),
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
        const firstDayOffset = Number.isFinite(firstDayHead?.offsetLeft) ? firstDayHead.offsetLeft : timelineGutterWidth();
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
        const top = ((now - timelineStart) / 60000) / 60 * currentTimelineHourHeight(timeline);
        const label = marker.querySelector('.hb-now-label');
        marker.style.setProperty('--hb-now-top', `${top.toFixed(2)}px`);
        marker.setAttribute('aria-label', `Current time ${formatTime(now)}`);
        if (label) label.textContent = formatTime(now);
    }

    function currentTimelineHourHeight(timeline) {
        const value = Number.parseFloat(getComputedStyle(timeline).getPropertyValue('--hb-hour-height'));
        return Number.isFinite(value) && value > 0 ? value : timelineHourHeight();
    }

    function updateTopbarCurrentTime() {
        const time = mount.querySelector('[data-current-time]');
        if (!time) return;
        const now = new Date();
        time.dateTime = now.toISOString();
        time.textContent = formatTopbarTime(now);
    }

    function friendlyError(error, action) {
        const message = error?.message || 'Something went wrong.';
        if (/failed to fetch/i.test(message)) return `Could not ${action}. Check your connection and try again.`;
        return message;
    }

    function errorMarkup(message) {
        if (!message) return '';
        const paywall = isPlanLimitMessage(message);
        return `
            <div class="${paywall ? 'hb-error hb-paywall-error' : 'hb-error'}">
                <div>
                    <strong>${paywall ? 'Upgrade to keep going' : 'Something needs attention'}</strong>
                    <span>${escapeHtml(message)}</span>
                </div>
                ${paywall ? '<a class="hb-button-secondary hb-paywall-cta" href="/pricing">View plans</a>' : ''}
            </div>`;
    }

    function isPlanLimitMessage(message) {
        const normalized = String(message || '').toLowerCase();
        return normalized.includes('current plan includes')
            || normalized.includes('current plan has limited')
            || normalized.includes('available on premium')
            || normalized.includes('ai usage limit')
            || normalized.includes('external lookup usage limit');
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
