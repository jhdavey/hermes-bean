import { Conversation } from '@elevenlabs/client';
import {
    appThemes,
    appThemesByKey,
    icons,
    subscriptionPlans,
    subscriptionTrialDays,
    systemDarkScheme,
    themeModes,
    themeModesByKey,
} from './config.js';
import { centeredMonthGridDays } from './calendarGrid.js';

export function reconcileAllDayEndDateInput(startValue, endValue) {
    const start = String(startValue || '').trim();
    const end = String(endValue || '').trim();
    if (end) return end;
    const parseDateInput = (value) => {
        const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value);
        if (!match) return null;
        const year = Number(match[1]);
        const month = Number(match[2]);
        const day = Number(match[3]);
        const date = new Date(Date.UTC(year, month - 1, day));
        return date.getUTCFullYear() === year
            && date.getUTCMonth() === month - 1
            && date.getUTCDate() === day
            ? date
            : null;
    };
    const parsedStart = parseDateInput(start);
    if (!parsedStart) return end;

    const next = new Date(parsedStart);
    next.setUTCDate(next.getUTCDate() + 1);
    return next.toISOString().slice(0, 10);
}

function normalizedSignupSource(value) {
    const source = String(value || '')
        .trim()
        .toLowerCase()
        .replace(/[^a-z0-9_-]+/g, '_')
        .replace(/^_+|_+$/g, '')
        .slice(0, 80);
    return source || 'direct_register';
}

export function mountHeyBeanWebApp(mount) {
    const logoUrl = mount.dataset.logo || '/images/bean-logo.png';
    const initialMode = mount.dataset.authMode || 'login';
    const fromLandingBean = mount.dataset.fromLandingBean === 'true';
    let signupSource = normalizedSignupSource(mount.dataset.signupSource || (fromLandingBean ? 'bean' : 'direct_register'));
    const initialSelectedPlan = ['base', 'premium', 'pro'].includes(mount.dataset.selectedPlan) ? mount.dataset.selectedPlan : '';
    const initialBillingInterval = mount.dataset.selectedBillingInterval === 'yearly' ? 'yearly' : 'monthly';
    const initialBillingStatus = new URLSearchParams(window.location.search).get('billing') || '';
    const initialSignupEmail = new URLSearchParams(window.location.search).get('email') || '';
    const publicThemeStorageKey = 'heybean.public.themeMode';
    const tokenKey = 'heybean.web.token';
    const rememberKey = 'heybean.web.remember';
    const activeWorkspaceKey = 'heybean.web.activeWorkspace';
    const dashboardChangeKey = 'heybean.dashboard.changeId';
    const dashboardDataCacheKey = 'heybean.dashboard.data';
    const calendarInitialWindowDays = 56;
    const calendarWindowChunkDays = 28;
    const state = {
        authMode: initialMode,
        selectedPlan: initialSelectedPlan,
        selectedBillingInterval: initialBillingInterval,
        guidedSignupStep: 'name',
        guidedSignupName: '',
        guidedSignupEmail: initialSignupEmail,
        guidedSignupPassword: '',
        guidedSignupThemeMode: initialGuidedSignupThemeMode(),
        guidedSignupError: '',
        subscriptionSummary: null,
        subscriptionCheckoutStatus: new URLSearchParams(window.location.search).get('checkout') || '',
        billingCheckoutStatus: initialBillingStatus,
        billingPaymentMethod: null,
        billingPaymentLoading: false,
        billingBusy: false,
        billingPlanInterval: initialBillingInterval,
        billingMessage: '',
        billingError: '',
        billingCouponCode: '',
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
        noteFolders: [],
        notes: [],
        dailyStickyNotes: new Map(),
        dailyStickyNoteLoadedKeys: new Set(),
        dailyStickyNoteLoadingKeys: new Set(),
        dailyStickyNoteStatuses: new Map(),
        selectedNoteId: '',
        selectedNoteFolderId: 'all',
        noteFoldersEditing: false,
        noteFolderDragId: '',
        notesSearch: '',
        notesDetailOpen: false,
        notesSort: 'recent',
        notesSaving: false,
        categories: [],
        settingsCategoryId: '',
        adminDashboardSummary: null,
        adminIssueSummary: null,
        adminPlanLimits: null,
        adminCoupons: null,
        adminLoading: false,
        adminUserGrowthRange: 'last_30_days',
        adminArchivedIssuesOpen: false,
        issueReportSubmitting: false,
        googleStatus: null,
        googleAuthUrl: '',
        outlookStatus: null,
        outlookAuthUrl: '',
        onboardingJustCompleted: false,
        signupPaywallDeferred: false,
        onboardingTourActive: false,
        onboardingTourStep: 0,
        calendarRefreshing: false,
        dashboardDataLoading: false,
        taskFilter: 'active',
        reminderFilter: 'scheduled',
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
        bean: {
            sessionId: '',
            panelOpen: false,
            mode: localStorage.getItem('heybean.bean.privacy') === 'listening' ? 'wake_listening' : 'privacy',
            statusText: localStorage.getItem('heybean.bean.privacy') === 'listening' ? 'Listening locally for “Hey Bean”' : 'Privacy mode',
            input: '',
            busy: false,
            messages: [],
            activity: [],
            confirmations: [],
            voiceActive: false,
            voiceConnecting: false,
            voiceTranscript: '',
            error: '',
        },
    };
    const externalCalendarImportPresets = [
        {
            key: 'apple',
            label: 'Apple Calendar',
            description: 'Paste an iCloud public calendar link from Apple Calendar.',
            linkLabel: 'iCloud public calendar link',
            linkHint: 'webcal://pXX-caldav.icloud.com/published/2/...',
        },
        {
            key: 'google',
            label: 'Google Calendar',
            description: 'Paste a Google secret iCal address for a one-time import.',
            linkLabel: 'Google secret iCal address',
            linkHint: 'https://calendar.google.com/calendar/ical/...',
        },
        {
            key: 'outlook',
            label: 'Outlook Calendar',
            description: 'Paste an Outlook published ICS link for a one-time import.',
            linkLabel: 'Outlook published ICS link',
            linkHint: 'https://outlook.live.com/owa/calendar/.../calendar.ics',
        },
        {
            key: 'proton',
            label: 'Proton Calendar',
            description: 'Paste a Proton share link for calendars shared with anyone.',
            linkLabel: 'Proton calendar share link',
            linkHint: 'https://calendar.proton.me/api/calendar/v1/url/...',
        },
        {
            key: 'yahoo',
            label: 'Yahoo Calendar',
            description: 'Paste a Yahoo iCal link or exported calendar URL.',
            linkLabel: 'Yahoo iCal link',
            linkHint: 'https://calendar.yahoo.com/.../calendar.ics',
        },
        {
            key: 'fastmail',
            label: 'Fastmail',
            description: 'Paste a Fastmail calendar sharing link.',
            linkLabel: 'Fastmail calendar link',
            linkHint: 'https://calendar.fastmail.com/.../calendar.ics',
        },
        {
            key: 'nextcloud',
            label: 'Nextcloud',
            description: 'Paste a public Nextcloud or ownCloud calendar subscription link.',
            linkLabel: 'Nextcloud public calendar link',
            linkHint: 'https://cloud.example.com/remote.php/dav/public-calendars/...',
        },
        {
            key: 'ics',
            label: 'Other iCal link',
            description: 'Use any public .ics or webcal calendar URL.',
            linkLabel: 'Public iCal or webcal link',
            linkHint: 'webcal://example.com/calendar.ics',
        },
    ];

    applyAppTheme();
    systemDarkScheme?.addEventListener?.('change', () => {
        if (currentThemeModeKey() === 'auto') applyAppTheme();
    });

    let timelineDrag = null;
    let timelineSuppressClick = false;
    let onboardingTourLayoutFrame = 0;
    let dashboardChangeAbort = null;
    let dashboardChangeLoopActive = false;
    let dashboardRefreshTimer = 0;
    let deferredDashboardRenderPending = false;
    let deferredDashboardRenderTimer = 0;
    let dashboardRefreshGeneration = 0;
    let localResourceSequence = -1;
    let activeNoteMarkdownEditor = null;
    let activeNoteMarkdownEditorId = '';
    let noteSaveStatusFadeTimer = 0;
    let noteMarkdownEditorConstructorPromise = null;
    const noteAutosaveTimers = new Map();
    const noteSaveInFlight = new Set();
    const pendingNoteSaveBodies = new Map();
    const pendingNoteFolderNames = new Set();
    const noteAutosaveDelay = 300;
    const dailyStickyNoteAutosaveTimers = new Map();
    const dailyStickyNoteSaveInFlight = new Set();
    const pendingDailyStickyNoteBodies = new Map();
    const dailyStickyNoteStatusFadeTimers = new Map();
    const dailyStickyNoteAutosaveDelay = 500;
    let workspaceSwitchGeneration = 0;
    let beanEventAbort = null;
    let beanEventLastId = 0;
    let beanMediaStream = null;
    let beanElevenLabsConversation = null;
    let beanWakeDetector = null;
    let beanWakeListeningStarting = false;
    let beanRealtimeSessionCache = null;
    let beanRealtimeSessionPromise = null;
    let beanPendingWakeTailTimer = 0;
    let beanPendingWakeTail = '';
    let beanSubmittedWakeTail = '';
    let beanPendingVoiceResponseTimer = 0;
    let beanPendingVoiceResponse = null;
    let beanVoiceBackgroundHandoff = null;
    let beanVoiceBackgroundHandoffCloseTimer = 0;
    const beanVoiceBackgroundHandoffMs = 10000;
    const beanVoiceBackgroundHandoffMinSpeakMs = 6500;
    const beanVoiceBackgroundHandoffMessage = 'This is taking a bit, so I’ll finish it in the background and come back when it’s ready.';
    const beanVoiceInitialIdleCloseMs = 9000;
    const beanVoiceFollowUpIdleCloseMs = 15000;
    let beanVoiceIdleTimer = 0;
    let beanLastVoiceActivityAt = 0;
    let beanVoiceInputIgnoreUntil = 0;
    let beanLastSpokenAnswer = '';
    let beanLastSpokenAnswerAt = 0;
    let beanVoiceRequestCount = 0;
    let beanVoiceClientSessionId = '';
    let beanVoiceClientTurnId = '';
    let beanLastAudioChunkAt = 0;
    let beanLastOutputVolumeAt = 0;
    let beanAudioPlaybackBlocked = false;
    let beanLiveKitDiagnosticsCleanup = null;
    let beanOutputVolumeProbeTimer = 0;
    let beanEventStatusStartedAt = Date.now();

    boot();
    bindInlineLandingSignupStart();
    bindPublicThemePreference();
    bindResponsiveCalendar();
    bindCurrentTimeTicker();
    bindDashboardLiveUpdateFallbacks();
    bindOnboardingTourViewport();
    bindDeferredDashboardRenderFlush();
    bindNoteAutosaveTeardown();

    async function boot() {
        if (initialMode === 'subscribe') {
            await loadSubscriptionPage();
            return;
        }
        if (state.token) {
            await loadSignedIn();
        } else {
            state.phase = initialMode === 'register' ? 'guidedOnboarding' : (initialMode === 'plain' ? 'plainSignup' : 'signedOut');
            if (state.phase === 'guidedOnboarding' || state.phase === 'plainSignup') resetGuidedSignupState();
            render();
        }
    }


    function bindInlineLandingSignupStart() {
        window.addEventListener('bean:inline-signup-started', (event) => {
            const detail = event.detail || {};
            const plan = ['base', 'premium', 'pro'].includes(detail.plan) ? detail.plan : '';
            const billingInterval = detail.billingInterval === 'yearly' ? 'yearly' : 'monthly';
            signupSource = normalizedSignupSource(detail.source || 'landing_inline');
            state.authMode = 'register';
            state.phase = 'guidedOnboarding';
            state.selectedPlan = plan;
            state.selectedBillingInterval = billingInterval;
            state.billingPlanInterval = billingInterval;
            state.subscriptionCheckoutStatus = '';
            state.billingCheckoutStatus = '';
            state.error = '';
            state.notice = '';
            resetGuidedSignupState({
                email: detail.email || '',
                themeMode: detail.themeMode || state.guidedSignupThemeMode || initialGuidedSignupThemeMode(),
            });
            render();
        });
    }

    function bindPublicThemePreference() {
        window.addEventListener('bean:landing-theme-mode-changed', (event) => {
            const mode = normalizeThemeModeKey(event.detail?.themeMode || event.detail?.mode);
            if (!['light', 'dark', 'auto'].includes(mode)) return;
            state.guidedSignupThemeMode = mode;
            if (state.phase === 'guidedOnboarding' && state.guidedSignupStep === 'themeMode') {
                state.guidedSignupError = '';
                render();
            }
        });
    }

    function initialGuidedSignupThemeMode() {
        const fromDataset = normalizeThemeModeKey(mount.dataset.initialThemeMode);
        if (mount.dataset.initialThemeMode && ['light', 'dark', 'auto'].includes(fromDataset)) return fromDataset;
        const fromGlobal = normalizeThemeModeKey(window.__heybeanPublicThemeMode || document.documentElement.dataset.publicThemeMode || document.body?.dataset.publicThemeMode);
        if (window.__heybeanPublicThemeMode || document.documentElement.dataset.publicThemeMode || document.body?.dataset.publicThemeMode) return fromGlobal;
        try {
            const stored = window.localStorage?.getItem(publicThemeStorageKey);
            const normalized = normalizeThemeModeKey(stored);
            if (stored && ['light', 'dark', 'auto'].includes(normalized)) return normalized;
        } catch (_) {}
        return 'light';
    }

    function bindNoteAutosaveTeardown() {
        window.addEventListener('pagehide', () => {
            flushAllNoteAutosaves({ keepalive: true });
            flushAllDailyStickyNoteAutosaves({ keepalive: true });
        });
    }

    async function loadSubscriptionPage() {
        state.phase = 'subscription';
        state.error = '';
        render();
        if (!state.token) {
            state.phase = 'guidedOnboarding';
            state.authMode = 'register';
            state.notice = '';
            state.error = '';
            resetGuidedSignupState();
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
                scheduleOnboardingTourLayout();
            }, 120);
        });
    }

    function bindOnboardingTourViewport() {
        window.addEventListener('scroll', () => {
            if (!state.onboardingTourActive) return;
            scheduleOnboardingTourLayout();
        }, true);
    }

    function bindCurrentTimeTicker() {
        window.setInterval(() => {
            if (state.phase !== 'signedIn') return;
            updateTopbarCurrentTime();
            if (state.selected !== 'today' || state.showMonth || state.modal) return;
            updateCurrentTimeMarker();
        }, 30000);
    }

    function bindDashboardLiveUpdateFallbacks() {
        window.addEventListener('focus', () => {
            if (state.phase !== 'signedIn') return;
            scheduleDashboardLiveRefresh();
            startDashboardChangeFeed();
        });
        window.setInterval(() => {
            if (state.phase !== 'signedIn') return;
            scheduleDashboardLiveRefresh();
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

    function isUnauthenticatedError(error) {
        const code = String(error?.payload?.error?.code || error?.payload?.code || '').toLowerCase();
        const message = String(error?.message || '').toLowerCase();
        return Number(error?.status) === 401
            || code === 'unauthenticated'
            || message === 'unauthenticated.';
    }

    function initialSelectedView() {
        return window.location.pathname === '/admin' ? 'admin' : 'today';
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

    let cachedClientLocation = null;
    let pendingClientLocationPromise = null;

    function browserTimezone() {
        try {
            return Intl.DateTimeFormat().resolvedOptions().timeZone || '';
        } catch (error) {
            return '';
        }
    }

    function userTimezone() {
        return String(state.user?.timezone || state.user?.time_zone || state.user?.timeZone || browserTimezone() || '').trim();
    }

    function clientTimezonePayload() {
        const timezone = userTimezone();
        return timezone ? { client_timezone: timezone } : {};
    }

    function isWeatherIntent(value) {
        return /\b(weather|forecast|temperature|temp|rain|snow|sleet|hail|storm|storms|wind|windy|umbrella|coat|jacket|degrees|outside|humidity)\b/i.test(String(value || ''));
    }

    async function clientLocationPayload(content = '') {
        if (!isWeatherIntent(content)) return {};
        const location = await browserLocationForWeather();
        return location ? { client_location: location } : {};
    }

    async function clientLocationPrehydrationPayload() {
        const cachedAt = Date.parse(cachedClientLocation?.captured_at || '');
        if (cachedClientLocation && Number.isFinite(cachedAt) && Date.now() - cachedAt < 15 * 60 * 1000) {
            return { client_location: cachedClientLocation };
        }
        if (!navigator.permissions?.query) return {};
        const permission = await navigator.permissions.query({ name: 'geolocation' }).catch(() => null);
        if (permission?.state !== 'granted') return {};
        const location = await browserLocationForWeather();
        return location ? { client_location: location } : {};
    }

    async function browserLocationForWeather() {
        const cachedAt = Date.parse(cachedClientLocation?.captured_at || '');
        if (cachedClientLocation && Number.isFinite(cachedAt) && Date.now() - cachedAt < 15 * 60 * 1000) {
            return cachedClientLocation;
        }
        if (!navigator.geolocation?.getCurrentPosition) return null;
        if (pendingClientLocationPromise) return pendingClientLocationPromise;

        pendingClientLocationPromise = (async () => {
            try {
                if (navigator.permissions?.query) {
                    const permission = await navigator.permissions.query({ name: 'geolocation' }).catch(() => null);
                    if (permission?.state === 'denied') return null;
                }
                const position = await new Promise((resolve, reject) => {
                    navigator.geolocation.getCurrentPosition(resolve, reject, {
                        enableHighAccuracy: false,
                        maximumAge: 15 * 60 * 1000,
                        timeout: 3500,
                    });
                });
                const latitude = Number(position?.coords?.latitude);
                const longitude = Number(position?.coords?.longitude);
                if (!Number.isFinite(latitude) || !Number.isFinite(longitude)) return null;
                cachedClientLocation = {
                    latitude: Number(latitude.toFixed(6)),
                    longitude: Number(longitude.toFixed(6)),
                    accuracy: Number.isFinite(position?.coords?.accuracy) ? Math.round(position.coords.accuracy) : undefined,
                    source: 'browser',
                    captured_at: new Date().toISOString(),
                };
                return cachedClientLocation;
            } catch (_) {
                return null;
            } finally {
                pendingClientLocationPromise = null;
            }
        })();

        return pendingClientLocationPromise;
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
            signal: options.signal,
            keepalive: options.keepalive === true,
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

    function newBeanVoiceEventId(prefix = 'voice') {
        return `${prefix}-${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 10)}`;
    }

    function logBeanVoiceLifecycleEvent(eventType, payload = {}) {
        if (!state.token) return;
        const occurredAtMs = Date.now();
        const body = {
            event_type: eventType,
            session_id: state.bean.sessionId || null,
            run_id: payload.run_id || payload.runId || null,
            mode: state.bean.mode || null,
            source: payload.source || 'web_realtime',
            label: payload.label || '',
            payload: {
                ...payload,
                event_client_ms: occurredAtMs,
                voice_client_session_id: beanVoiceClientSessionId || null,
                voice_client_turn_id: beanVoiceClientTurnId || null,
                bean_mode: state.bean.mode || null,
                busy: Boolean(state.bean.busy),
                voice_active: Boolean(state.bean.voiceActive),
                voice_connecting: Boolean(state.bean.voiceConnecting),
            },
            occurred_at: new Date(occurredAtMs).toISOString(),
            occurred_at_ms: occurredAtMs,
        };
        delete body.payload.run_id;
        delete body.payload.runId;
        api('/bean/voice-events', { method: 'POST', body, keepalive: true, timeoutMs: 2500 }).catch(() => {});
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
        if (state.phase === 'guidedOnboarding') return 'green';
        if (state.phase !== 'signedIn' && state.phase !== 'subscription') return 'green';
        return normalizeThemeKey(state.user?.theme);
    }

    function normalizeThemeModeKey(value) {
        const key = String(value || '').trim().toLowerCase();
        return themeModesByKey.has(key) ? key : 'auto';
    }

    function currentThemeModeKey() {
        if (state.phase === 'guidedOnboarding') return normalizeThemeModeKey(state.guidedSignupThemeMode);
        if (state.phase !== 'signedIn' && state.phase !== 'subscription') return 'auto';
        return normalizeThemeModeKey(state.user?.theme_mode || state.user?.themeMode);
    }

    function resolvedThemeMode(value = currentThemeModeKey()) {
        const mode = normalizeThemeModeKey(value);
        if (mode !== 'auto') return mode;
        return systemDarkScheme?.matches ? 'dark' : 'light';
    }

    function applyAppTheme(value = currentThemeKey()) {
        const theme = themeForKey(value);
        document.body.dataset.hbTheme = theme.key;
        const mode = currentThemeModeKey();
        const resolvedMode = resolvedThemeMode(mode);
        document.body.dataset.hbThemeMode = mode;
        document.body.dataset.hbThemeResolved = resolvedMode;
        document.querySelector('meta[name="theme-color"]')?.setAttribute('content', resolvedMode === 'dark' ? '#121712' : theme.accent);
    }

    function themeAccentColor() {
        return themeForKey(currentThemeKey()).accent;
    }

    function themeSettingsMarkup() {
        const selectedTheme = currentThemeKey();
        const selected = themeForKey(selectedTheme);
        const selectedMode = currentThemeModeKey();
        const resolvedMode = resolvedThemeMode(selectedMode);
        return `
            <div class="hb-surface-soft hb-card-pad hb-settings-section hb-settings-appearance-card hb-theme-settings">
                ${settingsSectionHeader(icons.palette, 'Appearance', `${selected.label} accent · ${selectedMode === 'auto' ? `Auto (${resolvedMode})` : themeModesByKey.get(selectedMode).label}`)}
                <div class="hb-theme-select-row">
                    <span class="hb-theme-swatch" style="--hb-theme-swatch: ${escapeAttr(selected.accent)}" aria-hidden="true"></span>
                    <label class="hb-label">Accent color
                        <select class="hb-select" data-theme-select aria-label="Accent color">
                            ${appThemes.map((theme) => `<option value="${escapeAttr(theme.key)}" ${theme.key === selectedTheme ? 'selected' : ''}>${escapeHtml(theme.label)}</option>`).join('')}
                        </select>
                    </label>
                </div>
                <div class="hb-theme-mode-group" role="radiogroup" aria-label="Theme mode">
                    ${themeModes.map((mode) => `
                        <label class="hb-theme-mode-option ${mode.key === selectedMode ? 'is-selected' : ''}">
                            <input type="radio" name="theme_mode" value="${escapeAttr(mode.key)}" data-theme-mode-option ${mode.key === selectedMode ? 'checked' : ''}>
                            <span>${escapeHtml(mode.label)}</span>
                            <small>${escapeHtml(mode.detail)}</small>
                        </label>
                    `).join('')}
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

    async function loadSignedIn(options = {}) {
        const deferInitialRender = options.deferInitialRender === true;
        state.phase = 'loading';
        state.dashboardDataLoading = false;
        if (!deferInitialRender) {
            render();
        }
        try {
            const user = await api('/auth/me');
            const accessState = String(user.access_state || user.accessState || '').trim().toLowerCase();
            state.user = user;
            if (accessState === 'waitlisted') {
                state.phase = 'waitlist';
                state.error = '';
                render();
                return;
            }
            if (userNeedsSignupPaywall(user)) {
                state.subscriptionSummary = await api('/billing/subscription').catch(() => null);
                state.phase = 'subscription';
                state.error = '';
                history.replaceState({}, '', `/subscribe?plan=${encodeURIComponent(state.selectedPlan || 'premium')}&billing_interval=${encodeURIComponent(normalizedBillingInterval(state.selectedBillingInterval))}`);
                render();
                return;
            }
            let refreshError = null;
            const recover = async (request, fallback) => {
                try {
                    return await request;
                } catch (error) {
                    refreshError ??= error;
                    return fallback;
                }
            };
            restoreRememberedActiveWorkspace(user);
            state.dashboardChangeLastId = Number(localStorage.getItem(dashboardChangeStorageKey()) || 0);
            const cachedWorkspaceId = currentWorkspaceIdFromUser(state.user);
            const cacheApplied = Boolean(cachedWorkspaceId && applyDashboardCache(cachedWorkspaceId));
            // Keep the launch screen covered until the authoritative workspace
            // snapshot is ready. Persisted data is a fallback, not a stale
            // intermediate frame for the user to watch reconcile.
            state.phase = 'loading';
            state.dashboardDataLoading = !cacheApplied;
            state.error = '';
            applyBillingReturnNotice();
            if (state.selected === 'admin') {
                loadAdminData();
            }
            if (!deferInitialRender) {
                render();
            }
            startDashboardChangeFeed();

            const notesAllowed = notesEnabled();
            const [summary, tasks, pastTasks, reminders, calendar, noteFolders, notes, categories, googleStatus, outlookStatus, subscription, billingPayment] = await Promise.all([
                recover(api(workspaceScopedPath('/today')), state.summary || {}),
                recover(api(workspaceScopedPath('/tasks')), state.tasks),
                recover(api(workspaceScopedPath('/tasks/past')), []),
                recover(api(workspaceScopedPath('/reminders')), state.reminders),
                recover(api(workspaceScopedPath('/calendar-events?skip_google_sync=1&skip_outlook_sync=1')), state.calendar),
                notesAllowed ? recover(api(workspaceScopedPath('/note-folders')), state.noteFolders) : Promise.resolve([]),
                notesAllowed ? recover(api(workspaceScopedPath('/notes')), state.notes) : Promise.resolve([]),
                recover(api(workspaceScopedPath('/event-categories')), state.categories),
                api('/google-calendar/status?cached=1').catch(() => null),
                api('/outlook-calendar/status?cached=1').catch(() => null),
                api('/billing/subscription').catch(() => state.subscriptionSummary),
                api('/billing/payment-method').catch(() => ({ payment_method: null })),
            ]);
            state.user = mergeUser(user, summary?.user, summary);
            state.summary = summary;
            state.subscriptionSummary = subscription || state.subscriptionSummary;
            state.billingPaymentMethod = billingPayment?.payment_method || billingPayment?.paymentMethod || null;
            const workspaceId = currentWorkspaceId();
            if (workspaceId) {
                setActiveWorkspaceLocally(workspaceId, { persist: false });
            }
            state.tasks = reconcileTaskRefresh(mergeById(normalizeList(tasks.length ? tasks : summary?.tasks), normalizeList(pastTasks)));
            state.reminders = reconcileReminderRefresh(reminders.length ? reminders : summary?.reminders);
            state.calendar = reconcileCalendarRefresh(calendar.length ? calendar : summary?.calendar_events);
            state.noteFolders = normalizeNoteFolders(noteFolders);
            state.notes = normalizeNotes(notes);
            ensureSelectedNote();
            state.categories = normalizeList(categories);
            state.googleStatus = googleStatus;
            state.outlookStatus = outlookStatus;
            state.phase = 'signedIn';
            startBeanEventFeed();
            if (localStorage.getItem('heybean.bean.privacy') === 'listening') {
                startBeanWakeListening();
            }
            state.dashboardDataLoading = false;
            // A partial background refresh should never become a global red
            // error when the cached/remaining resources can render normally.
            state.error = '';
            applyBillingReturnNotice();
            saveDashboardCache();
            renderDashboardDataUpdate({ deferIfEditing: true });
            refreshCalendarInBackground();
        } catch (error) {
            stopDashboardChangeFeed();
            stopBeanEventFeed();
            state.dashboardDataLoading = false;
            if (isUnauthenticatedError(error)) {
                clearToken();
                state.phase = 'signedOut';
            } else if (state.user) {
                state.phase = 'signedIn';
            } else {
                state.phase = 'signedOut';
            }
            state.error = friendlyError(error, 'load your account');
            render();
        }
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
                noteFolders: state.noteFolders,
                notes: state.notes,
                categories: state.categories,
                googleStatus: state.googleStatus,
                outlookStatus: state.outlookStatus,
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
            state.noteFolders = normalizeNoteFolders(cached.noteFolders);
            state.notes = normalizeNotes(cached.notes);
            ensureSelectedNote();
            state.categories = normalizeList(cached.categories);
            state.googleStatus = cached.googleStatus || state.googleStatus;
            state.outlookStatus = cached.outlookStatus || state.outlookStatus;
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
            notes_recent: [],
        };
        state.tasks = [];
        state.reminders = [];
        state.calendar = [];
        state.noteFolders = [];
        state.notes = [];
        state.selectedNoteId = '';
        state.selectedNoteFolderId = 'all';
        state.categories = [];
    }

    function snapshotDashboardState() {
        return {
            user: state.user,
            summary: state.summary,
            tasks: state.tasks,
            reminders: state.reminders,
            calendar: state.calendar,
            noteFolders: state.noteFolders,
            notes: state.notes,
            categories: state.categories,
            googleStatus: state.googleStatus,
            outlookStatus: state.outlookStatus,
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
        state.noteFolders = snapshot.noteFolders;
        state.notes = snapshot.notes;
        state.categories = snapshot.categories;
        state.googleStatus = snapshot.googleStatus;
        state.outlookStatus = snapshot.outlookStatus;
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

    function userNeedsSignupPaywall(user = state.user, subscription = state.subscriptionSummary) {
        if (!user) return false;
        const accessState = String(user.access_state || user.accessState || '').trim().toLowerCase();
        if (accessState === 'active') return false;
        if (accessState === 'subscription_required') return true;
        if (user.is_admin === true || user.isAdmin === true) return false;
        const tier = String(user.subscription_tier || user.subscriptionTier || '').trim().toLowerCase();
        if (tier === 'enterprise') return false;
        const status = String(
            subscription?.status
            || user.subscription_status
            || user.subscriptionStatus
            || ''
        ).trim().toLowerCase();
        return status !== 'active' && status !== 'trialing';
    }

    function resetGuidedSignupState(options = {}) {
        state.guidedSignupStep = options.step || 'name';
        state.guidedSignupName = options.name || '';
        state.guidedSignupEmail = options.email ?? initialSignupEmail;
        state.guidedSignupPassword = '';
        state.guidedSignupThemeMode = options.themeMode || 'light';
        state.guidedSignupError = options.error || '';
        state.busy = false;
        state.notice = options.notice || '';
    }

    function guidedSignupInputLocked() {
        return state.busy || state.guidedSignupStep === 'plan';
    }

    function startGuidedSignup() {
        state.authMode = 'register';
        state.phase = 'guidedOnboarding';
        state.error = '';
        state.notice = '';
        resetGuidedSignupState({
            themeMode: state.guidedSignupThemeMode || 'light',
        });
        history.pushState({}, '', '/register');
        render();
    }

    function startPlainSignup() {
        state.authMode = 'register';
        state.phase = 'plainSignup';
        state.error = '';
        state.notice = '';
        resetGuidedSignupState({
            themeMode: state.guidedSignupThemeMode || 'light',
        });
        history.pushState({}, '', '/register?mode=plain');
        render();
    }

    function normalizeNoteFolders(value) {
        return normalizeList(value).map((folder) => ({
            ...folder,
            sort_order: Number(folder?.sort_order ?? folder?.sortOrder ?? 0),
        })).sort(compareNoteFolders);
    }

    function compareNoteFolders(a, b) {
        const order = Number(a?.sort_order ?? a?.sortOrder ?? 0) - Number(b?.sort_order ?? b?.sortOrder ?? 0);
        if (order) return order;
        const name = String(a?.name || '').localeCompare(String(b?.name || ''));
        if (name) return name;
        return Number(a?.id || 0) - Number(b?.id || 0);
    }

    function normalizeNotes(value) {
        return normalizeList(value).map((note) => ({
            ...note,
            metadata: normalizeNoteMetadata(note?.metadata),
            is_pinned: Boolean(note.is_pinned ?? note.isPinned),
            note_folder_id: note.note_folder_id ?? note.noteFolderId ?? note.folder_id ?? note.folderId ?? null,
        })).sort(compareNotes);
    }

    function normalizeNoteMetadata(metadata) {
        if (metadata && typeof metadata === 'object' && !Array.isArray(metadata)) return metadata;
        if (typeof metadata === 'string' && metadata.trim()) {
            try {
                const parsed = JSON.parse(metadata);
                return parsed && typeof parsed === 'object' && !Array.isArray(parsed) ? parsed : {};
            } catch (_) {
                return {};
            }
        }
        return {};
    }

    function compareNotes(a, b) {
        const pinned = Number(Boolean(b?.is_pinned ?? b?.isPinned)) - Number(Boolean(a?.is_pinned ?? a?.isPinned));
        if (pinned) return pinned;
        return new Date(b?.updated_at || b?.updatedAt || 0) - new Date(a?.updated_at || a?.updatedAt || 0);
    }

    function ensureSelectedNote() {
        const notes = filteredNotes();
        if (notes.some((note) => String(note.id) === String(state.selectedNoteId))) return;
        state.selectedNoteId = notes[0]?.id ? String(notes[0].id) : '';
    }

    function selectedNote() {
        return state.notes.find((note) => String(note.id) === String(state.selectedNoteId)) || null;
    }

    function noteIsLocked(note) {
        const metadata = normalizeNoteMetadata(note?.metadata);
        return metadata.locked === true || metadata.is_locked === true || metadata.locked === 'true';
    }

    function filteredNotes() {
        const requestedFolderId = String(state.selectedNoteFolderId || 'all');
        const folderId = requestedFolderId === 'unfiled' ? 'all' : requestedFolderId;
        const search = String(state.notesSearch || '').trim().toLowerCase();
        return normalizeNotes(state.notes).filter((note) => {
            const noteFolderId = String(note.note_folder_id || note.noteFolderId || '');
            const folderMatch = folderId === 'all'
                || (folderId === 'pinned' && Boolean(note.is_pinned ?? note.isPinned))
                || noteFolderId === folderId;
            if (!folderMatch) return false;
            if (!search) return true;
            const folder = noteFolder(note);
            return [
                note.title,
                note.plain_text,
                note.plainText,
                folder?.name,
            ].some((value) => String(value || '').toLowerCase().includes(search));
        });
    }

    function currentNotesFolderTitle() {
        const requestedFolderId = String(state.selectedNoteFolderId || 'all');
        const folderId = requestedFolderId === 'unfiled' ? 'all' : requestedFolderId;
        if (folderId === 'pinned') return 'Pinned';
        if (folderId === 'all') return 'All Notes';
        return state.noteFolders.find((folder) => String(folder.id) === folderId)?.name || 'All Notes';
    }

    function sortedNotesForList(notes) {
        const sorted = [...notes];
        if (state.notesSort === 'title') {
            return sorted.sort((a, b) => String(a.title || '').localeCompare(String(b.title || '')));
        }
        return sorted.sort((a, b) => new Date(b?.updated_at || b?.updatedAt || 0) - new Date(a?.updated_at || a?.updatedAt || 0));
    }

    function noteListSections(notes) {
        const sorted = sortedNotesForList(notes);
        const pinned = sorted.filter((note) => note.is_pinned || note.isPinned);
        const unpinned = sorted.filter((note) => !(note.is_pinned || note.isPinned));
        const now = Date.now();
        const day = 24 * 60 * 60 * 1000;
        const recent = [];
        const previous30 = [];
        const older = [];
        unpinned.forEach((note) => {
            const time = new Date(note.updated_at || note.updatedAt || 0).getTime();
            const age = Number.isFinite(time) ? now - time : Infinity;
            if (age <= 7 * day) recent.push(note);
            else if (age <= 30 * day) previous30.push(note);
            else older.push(note);
        });
        return [
            { title: pinned.length ? 'Pinned' : '', notes: pinned },
            { title: 'Recent', notes: recent },
            { title: 'Previous 30 Days', notes: previous30 },
            { title: 'Older', notes: older },
        ].filter((section) => section.notes.length);
    }

    function noteFolder(note) {
        const id = String(note?.note_folder_id || note?.noteFolderId || note?.folder_id || note?.folderId || '');
        return state.noteFolders.find((folder) => String(folder.id) === id) || null;
    }

    function linkedWorkspaceIdsForNote(note) {
        const source = normalizeList(note?.linked_workspace_ids || note?.linkedWorkspaceIds);
        return new Set(source.map((id) => String(id)));
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
        destroyActiveNoteMarkdownEditor();
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
            : state.phase === 'guidedOnboarding'
                ? guidedOnboardingMarkup()
            : state.phase === 'plainSignup'
                ? plainSignupMarkup()
            : state.phase === 'subscription'
                ? subscriptionSignupMarkup()
            : state.phase === 'waitlist'
                ? earlyAccessWaitlistMarkup()
                : signedOutMarkup();
        bindCommonActions();
        if (state.phase === 'subscription' || state.phase === 'waitlist') bindSubscriptionActions();
        if (state.phase === 'signedIn') bindSignedInActions();
        if (state.phase === 'guidedOnboarding' || state.phase === 'subscription') {
            scrollGuidedOnboardingContent();
        }
        scheduleOnboardingTourLayout();
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
        if (modal.type === 'external-calendar-import') {
            return [
                modal.type,
                modal.providerKey || 'apple',
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
        const forgot = state.authMode === 'forgot';
        return `
            <div class="hb-app">
                <main class="hb-auth-wrap">
                    <section class="hb-card hb-auth-card">
                        <div class="hb-auth-title">
                            <img src="${escapeAttr(logoUrl)}" alt="">
                            <div>
                                <h1>${forgot ? 'Reset password' : 'Login'}</h1>
                            </div>
                        </div>
                        ${errorMarkup(state.error)}
                        ${state.notice ? `<div class="hb-success">${escapeHtml(state.notice)}</div>` : ''}
                        ${forgot ? forgotFormMarkup() : authFormMarkup()}
                        <div class="hb-auth-links">
                            <a class="hb-button-ghost" href="/privacy">Privacy</a>
                            <a class="hb-button-ghost" href="/terms">Terms</a>
                            <a class="hb-button-ghost" href="/support">Support</a>
                        </div>
                    </section>
                </main>
            </div>`;
    }

    function authFormMarkup() {
        return `
            <form class="hb-form" data-action="login">
                ${labelInput('Email', 'email', 'email', '', 'required autocomplete="email"')}
                ${labelInput('Password', 'password', 'password', '', 'required autocomplete="current-password" minlength="1"')}
                <label class="hb-checkbox-row"><input type="checkbox" name="remember" ${state.remember ? 'checked' : ''}> Remember me</label>
                <button class="hb-button" type="submit" ${state.busy ? 'disabled' : ''}>${state.busy ? 'Signing in…' : 'Sign in'}</button>
                <div class="hb-link-row">
                    <button class="hb-button-ghost" type="button" data-auth-mode="register">Start with Bean</button>
                    <button class="hb-button-ghost" type="button" data-plain-signup>Use plain signup form</button>
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
                    <button class="hb-button-ghost" type="button" data-auth-mode="register">Start with Bean</button>
                    <button class="hb-button-ghost" type="button" data-plain-signup>Use plain signup form</button>
                </div>
            </form>`;
    }

    async function submitGuidedOnboarding(event) {
        event.preventDefault();
        if (guidedSignupInputLocked()) return;
        const step = state.guidedSignupStep;
        const input = event.currentTarget.querySelector('[name="value"]');
        const rawValue = String(input?.value || '');
        const value = step === 'password' ? rawValue : rawValue.trim();
        if (!value) return;
        state.guidedSignupError = '';
        if (step === 'name') {
            await handleGuidedSignupName(value);
            return;
        }
        if (step === 'themeMode') {
            selectGuidedThemeMode(value);
            return;
        }
        if (step === 'email') {
            await handleGuidedSignupEmail(value);
            return;
        }
        if (step === 'password') {
            await handleGuidedSignupPassword(value);
            return;
        }
    }

    function dispatchSignupVoiceProgress(detail = {}) {
        window.dispatchEvent(new CustomEvent('bean:signup-progress', {
            detail: {
                source: 'guided_onboarding',
                ...detail,
            },
        }));
    }

    function dispatchSignupVoiceActivity(detail = {}) {
        window.dispatchEvent(new CustomEvent('bean:signup-activity', {
            detail: {
                source: 'guided_onboarding',
                step: state.guidedSignupStep,
                ...detail,
            },
        }));
    }

    async function handleGuidedSignupName(value) {
        const name = value.trim();
        if (name.length < 2) {
            state.guidedSignupError = 'Please enter the name you want Bean to use.';
            render();
            return;
        }
        state.guidedSignupName = name;
        state.guidedSignupStep = 'themeMode';
        render();
    }

    function guidedThemeModeFromText(value) {
        const normalized = String(value || '').trim().toLowerCase();
        if (['system', 'device', 'automatic'].includes(normalized)) return 'auto';
        if (['light', 'dark', 'auto'].includes(normalized)) return normalized;
        return '';
    }

    function selectGuidedThemeMode(key) {
        const normalized = guidedThemeModeFromText(key);
        if (!normalized) {
            state.guidedSignupError = 'Choose Light, Dark, or Auto.';
            render();
            return;
        }
        state.guidedSignupThemeMode = normalized;
        state.guidedSignupError = '';
        try {
            window.localStorage?.setItem(publicThemeStorageKey, normalized);
        } catch (_) {}
        window.dispatchEvent(new CustomEvent('bean:landing-theme-mode-requested', {
            detail: { themeMode: normalized, source: 'guided_signup' },
        }));
        state.guidedSignupStep = 'email';
        render();
    }

    async function handleGuidedSignupEmail(value) {
        const email = value.trim().toLowerCase();
        state.guidedSignupEmail = email;
        if (!looksLikeGuidedSignupEmail(email)) {
            state.guidedSignupError = 'That email does not look valid. Please text the address you want to use.';
            render();
            return;
        }
        state.busy = true;
        render();
        try {
            const availability = await api('/auth/email-availability', { method: 'POST', body: { email } });
            state.busy = false;
            if (!availability.available) {
                state.guidedSignupError = 'That email is already connected to an account. Please send Bean a different one.';
                render();
                return;
            }
            state.guidedSignupEmail = availability.email;
            state.guidedSignupStep = 'password';
            render();
        } catch (_) {
            state.busy = false;
            state.guidedSignupError = 'I could not check that email right now. Please try again in a moment.';
            render();
        }
    }

    function looksLikeGuidedSignupEmail(value) {
        if (String(value || '').length > 254) return false;
        return /^[a-z0-9._%+-]+@(?:[a-z0-9-]+\.)+[a-z]{2,}$/i.test(String(value || ''));
    }

    async function handleGuidedSignupPassword(value) {
        if (value.length < 12) {
            state.guidedSignupError = 'Use at least 12 characters so your account is protected.';
            render();
            return;
        }
        state.guidedSignupPassword = value;
        state.busy = true;
        render();
        try {
            const result = await registerGuidedSignupAccount({ password: value });
            persistToken(result.token, true);
            state.user = result.user || null;
            state.subscriptionSummary = null;
            state.busy = false;
            if (state.user?.access_state === 'waitlisted' || state.user?.accessState === 'waitlisted') {
                removePublicSignupBeanPresence({ delayMs: 3600 });
                state.phase = 'waitlist';
                state.guidedSignupError = '';
                render();
                return;
            }
            state.guidedSignupStep = 'plan';
            startSignupDashboardPreview(result);
        } catch (error) {
            state.busy = false;
            state.guidedSignupError = friendlyError(error, 'create your account');
            render();
        }
    }

    async function registerGuidedSignupAccount({ password }) {
        return api('/auth/register', {
            method: 'POST',
            body: {
                name: state.guidedSignupName,
                email: state.guidedSignupEmail,
                password,
                password_confirmation: password,
                theme_mode: state.guidedSignupThemeMode,
                source: signupSource,
                ...(state.selectedPlan ? { plan: state.selectedPlan } : {}),
                billing_interval: normalizedBillingInterval(state.selectedBillingInterval),
            },
        });
    }

    function removePublicSignupBeanPresence(options = {}) {
        const root = document.querySelector('.public-bean-presence-signup[data-public-bean]');
        if (!root) return;
        const active = root.dataset.mode && root.dataset.mode !== 'disabled';
        const delayMs = active ? Math.max(0, Number(options.delayMs || 120)) : 0;
        const removeRoot = () => root.remove();
        if (active && delayMs > 0) {
            window.setTimeout(() => {
                root.querySelector('[data-public-bean-toggle]')?.click();
                window.setTimeout(removeRoot, 120);
            }, delayMs);
            return;
        }
        if (active) {
            root.querySelector('[data-public-bean-toggle]')?.click();
            window.setTimeout(removeRoot, 120);
            return;
        }
        removeRoot();
    }

    function startSignupDashboardPreview(result = {}) {
        state.signupPaywallDeferred = true;
        state.subscriptionCheckoutStatus = '';
        state.subscriptionSummary = null;
        state.selectedPlan = result.selected_plan || state.selectedPlan || 'premium';
        state.selectedBillingInterval = normalizedBillingInterval(result.selected_billing_interval || state.selectedBillingInterval);
        state.phase = 'signedIn';
        state.selected = 'today';
        state.showMonth = false;
        state.dashboardDataLoading = false;
        state.error = '';
        state.notice = '';
        state.onboardingTourActive = false;
        state.onboardingTourStep = 0;
        state.modal = { type: 'post-signup-bean-choice' };
        history.pushState({}, '', '/app?welcome=1');
        render();
        dispatchPostSignupBeanChime();
    }

    function openDeferredSignupPaywall(message = 'Choose a plan to continue with HeyBean.') {
        if (!state.signupPaywallDeferred && !userNeedsSignupPaywall()) return false;
        removePublicSignupBeanPresence();
        state.signupPaywallDeferred = false;
        state.onboardingTourActive = false;
        state.onboardingTourStep = 0;
        state.modal = null;
        state.error = '';
        state.notice = message;
        openGuidedPlanSelection();
        return true;
    }

    function openGuidedPlanSelection() {
        state.phase = 'subscription';
        state.busy = false;
        state.guidedSignupStep = 'plan';
        state.error = '';
        history.pushState({}, '', `/subscribe?plan=${encodeURIComponent(state.selectedPlan || 'premium')}&billing_interval=${encodeURIComponent(normalizedBillingInterval(state.selectedBillingInterval))}`);
        render();
    }


    function postSignupBeanChoiceMessage() {
        return 'Alright, your account is created. Now I can give you a quick tour of the dashboard, help you get started, or you can skip all of that stuff and just dive in.';
    }

    function dispatchPostSignupBeanChime() {
        window.dispatchEvent(new CustomEvent('bean:post-signup-chime', {
            detail: { message: postSignupBeanChoiceMessage(), autoVoice: fromLandingBean },
        }));
    }

    function startPostSignupDashboardTour() {
        state.modal = null;
        activateOnboardingTourStep(0);
    }

    function startPostSignupFirstActionChoice() {
        state.onboardingTourActive = false;
        state.onboardingTourStep = 0;
        state.modal = { type: 'post-tour-first-action', step: 'choose' };
    }

    function guidedOnboardingMarkup() {
        const step = state.guidedSignupStep;
        return `
            <div class="hb-app hb-guided-immersive-app hb-guided-zero-chrome-app">
                <main class="hb-guided-onboarding-shell hb-guided-chat-shell hb-guided-immersive-shell hb-guided-zero-chrome-shell">
                    <section class="hb-guided-onboarding-stage hb-guided-immersive-stage hb-guided-zero-chrome-stage" aria-live="polite">
                        <div class="hb-guided-onboarding-content hb-guided-chat-content hb-guided-zero-chrome-content" data-guided-content>
                            ${guidedChatTranscriptMarkup()}
                            ${step === 'themeMode' ? guidedThemeModePanelMarkup() : ''}
                            ${guidedOnboardingStatusMarkup()}
                            ${['name', 'themeMode', 'email', 'password'].includes(step) ? guidedOnboardingComposerMarkup(step) : ''}
                        </div>
                    </section>
                </main>
            </div>`;
    }

    function guidedChatTranscriptMarkup() {
        return `<section class="hb-guided-chat-log hb-guided-zero-chrome-message" aria-label="Bean signup message">
            ${guidedChatMessageMarkup(['bean', state.busy ? 'Bean is thinking…' : guidedCurrentBeanMessage()])}
        </section>`;
    }

    function guidedCurrentBeanMessage() {
        if (state.guidedSignupStep === 'themeMode') {
            return 'Choose Light, Dark, or Auto.';
        }
        if (state.guidedSignupStep === 'email') {
            const mode = themeModesByKey.get(state.guidedSignupThemeMode) || themeModesByKey.get('auto');
            return `${mode.label} it is. What email should I use for your account?`;
        }
        if (state.guidedSignupStep === 'password') {
            return 'Choose a password. Type it — don’t say it.';
        }
        if (state.guidedSignupStep === 'plan') {
            return 'I’ll show you around your dashboard now.';
        }
        return 'What is your first and last name?';
    }

    function guidedChatMessageMarkup([role, text]) {
        const bean = role === 'bean';
        return `<div class="hb-guided-chat-bubble ${bean ? 'hb-guided-chat-bubble-bean' : 'hb-guided-chat-bubble-user'}"><strong>${bean ? 'Bean' : 'You'}</strong><span>${escapeHtml(text)}</span></div>`;
    }

    function guidedOnboardingComposerMarkup(step) {
        if (!['name', 'themeMode', 'email', 'password'].includes(step)) return '';
        const disabled = guidedSignupInputLocked();
        const field = {
            name: { type: 'text', value: '', autocomplete: 'name', placeholder: 'Type your name…', attrs: 'minlength="2" maxlength="255"' },
            themeMode: { type: 'text', value: '', autocomplete: 'off', placeholder: 'Light, Dark, or Auto…', attrs: '' },
            email: { type: 'email', value: state.guidedSignupEmail, autocomplete: 'email', placeholder: 'Type your email…', attrs: 'maxlength="254"' },
            password: { type: 'password', value: '', autocomplete: 'new-password', placeholder: 'Type your password…', attrs: 'minlength="12"' },
        }[step];
        return `
            <form class="hb-guided-chat-composer" data-action="guided-onboarding" data-guided-onboarding-step="${escapeAttr(step)}">
                <input class="hb-input" name="value" type="${field.type}" value="${escapeAttr(field.value)}" autocomplete="${field.autocomplete}" placeholder="${escapeAttr(field.placeholder)}" ${field.attrs} required ${disabled ? 'disabled' : ''}>
                <button class="hb-button" type="submit" ${disabled ? 'disabled' : ''}>${state.busy ? 'Saving…' : 'Send'}</button>
            </form>`;
    }

    function guidedOnboardingStatusMarkup() {
        if (!state.guidedSignupError) return '';
        return `<div class="hb-guided-onboarding-error">${escapeHtml(state.guidedSignupError)}</div>`;
    }

    function guidedThemeModePanelMarkup() {
        return `
            <section class="hb-guided-choice-panel" aria-label="Theme mode">
                ${['light', 'dark', 'auto'].map((key) => {
                    const mode = themeModesByKey.get(key);
                    return `<button class="hb-guided-choice-chip ${state.guidedSignupThemeMode === key ? 'hb-guided-choice-chip-active' : ''}" type="button" data-guided-theme-mode="${escapeAttr(key)}" ${guidedSignupInputLocked() ? 'disabled' : ''}>${escapeHtml(mode.label)}</button>`;
                }).join('')}
            </section>`;
    }

    function plainSignupMarkup() {
        return `
            <div class="hb-app">
                <main class="hb-auth-wrap hb-auth-wrap-register">
                    <section class="hb-card hb-auth-card hb-auth-card-register">
                        <div class="hb-auth-title">
                            <img src="${escapeAttr(logoUrl)}" alt="">
                            <div><h1>Plain signup</h1><p class="hb-item-meta">Create your account with a standard form. You can still use Bean once you are inside.</p></div>
                        </div>
                        ${errorMarkup(state.error)}
                        <form class="hb-form" data-action="plain-signup">
                            ${labelInput('Name', 'name', 'text', state.guidedSignupName, 'required autocomplete="name" minlength="2" maxlength="255"')}
                            ${labelInput('Email', 'email', 'email', state.guidedSignupEmail, 'required autocomplete="email" maxlength="254"')}
                            ${labelInput('Password', 'password', 'password', '', 'required autocomplete="new-password" minlength="12"')}
                            <section class="hb-guided-choice-panel" aria-label="Theme mode">
                                ${['light', 'dark', 'auto'].map((key) => {
                                    const mode = themeModesByKey.get(key);
                                    return `<button class="hb-guided-choice-chip ${state.guidedSignupThemeMode === key ? 'hb-guided-choice-chip-active' : ''}" type="button" data-guided-theme-mode="${escapeAttr(key)}" ${state.busy ? 'disabled' : ''}>${escapeHtml(mode.label)}</button>`;
                                }).join('')}
                            </section>
                            <button class="hb-button" type="submit" ${state.busy ? 'disabled' : ''}>${state.busy ? 'Creating account…' : 'Create account'}</button>
                        </form>
                        <div class="hb-link-row">
                            <button class="hb-button-ghost" type="button" data-auth-mode="register">Start with Bean instead</button>
                            <button class="hb-button-ghost" type="button" data-auth-mode="login">Back to login</button>
                        </div>
                    </section>
                </main>
            </div>`;
    }

    async function submitPlainSignup(event) {
        event.preventDefault();
        const form = event.currentTarget;
        const name = String(form.elements.name?.value || '').trim();
        const email = String(form.elements.email?.value || '').trim().toLowerCase();
        const password = String(form.elements.password?.value || '');
        state.guidedSignupName = name;
        state.guidedSignupEmail = email;
        state.error = '';
        if (name.length < 2) {
            state.error = 'Enter your name.';
            render();
            return;
        }
        if (!looksLikeGuidedSignupEmail(email)) {
            state.error = 'Enter a valid email address.';
            render();
            return;
        }
        if (password.length < 12) {
            state.error = 'Use at least 12 characters.';
            render();
            return;
        }
        state.busy = true;
        render();
        try {
            const result = await registerGuidedSignupAccount({ password });
            persistToken(result.token, true);
            state.user = result.user || null;
            state.subscriptionSummary = null;
            state.busy = false;
            if (state.user?.access_state === 'waitlisted' || state.user?.accessState === 'waitlisted') {
                removePublicSignupBeanPresence();
                state.phase = 'waitlist';
                render();
                return;
            }
            startSignupDashboardPreview(result);
        } catch (error) {
            state.busy = false;
            state.error = friendlyError(error, 'create your account');
            render();
        }
    }

    function earlyAccessWaitlistMarkup() {
        return `<div class="hb-app">
            <main class="hb-auth-wrap hb-auth-wrap-register">
                <section class="hb-card hb-auth-card hb-auth-card-register">
                    <div class="hb-auth-title">
                        <img src="${escapeAttr(logoUrl)}" alt="Bean">
                        <div><h1>Your account is created</h1><p class="hb-item-meta">You’re on the early-access waitlist.</p></div>
                    </div>
                    <div class="hb-success"><strong>Your place is saved.</strong><span>Unfortunately, it looks like we’re currently at capacity. Since we’re doing a controlled rollout, I’ll add you to the waitlist and let you know when we can continue onboarding. It’s usually within 1–2 days.</span></div>
                    <div class="hb-link-row"><a class="hb-button-secondary" href="/">Back to HeyBean</a><button class="hb-button-ghost" type="button" data-subscribe-logout>Use another email</button></div>
                </section>
            </main>
        </div>`;
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
        const introMessage = confirmed
            ? subscriptionConfirmationCopy(liveConfirmed, selectedPlan)
            : 'Your account has been created. Check your email to verify. Next, choose the plan that fits your calendar, tasks, reminders, and notes.';
        return `
            <div class="hb-app">
                <main class="hb-guided-onboarding-shell hb-guided-onboarding-shell-subscribe">
                    <div class="hb-guided-onboarding-topbar">
                        <button class="hb-button-ghost" type="button" data-subscribe-logout>Use a different account</button>
                    </div>
                    <section class="hb-guided-onboarding-stage">
                        <div class="hb-guided-onboarding-content">
                            <section class="hb-card hb-guided-setup-card">
                                ${subscriptionProgressMarkup(3)}
                                <h1>Choose a plan</h1>
                                <p>${escapeHtml(introMessage)}</p>
                                ${state.notice ? `<div class="hb-success">${escapeHtml(state.notice)}</div>` : ''}
                                ${canceled ? '<div class="hb-error"><strong>Checkout was canceled</strong><span>No charge was made. Choose a plan when you are ready to continue.</span></div>' : ''}
                                ${errorMarkup(state.error)}
                                <section class="hb-guided-choice-panel hb-guided-plan-panel">
                                    ${confirmed ? subscriptionConfirmationMarkup(selectedPlan, subscription, liveConfirmed) : subscriptionPlanSelectionMarkup(selectedPlan)}
                                </section>
                            </section>
                        </div>
                    </section>
                </main>
                <div class="hb-guided-onboarding-bean-dock" aria-hidden="true"><img src="${escapeAttr(logoUrl)}" alt=""></div>
            </div>`;
    }

    function subscriptionProgressMarkup(activeStep) {
        const steps = [
            ['1', 'Account'],
            ['2', 'Account setup'],
            ['3', 'Plan'],
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
        const billingInterval = normalizedBillingInterval(state.selectedBillingInterval);
        return `
            <div class="hb-guided-plan-header">
                <strong>${escapeHtml(`${subscriptionTrialDays}-day free trial`)}</strong>
                <span>Billing begins after the trial and renews ${billingInterval === 'yearly' ? 'yearly' : 'monthly'} until canceled.</span>
            </div>
            ${billingIntervalToggleMarkup(billingInterval, 'subscribe-billing-interval')}
            <div class="hb-subscribe-grid">
                ${Object.entries(subscriptionPlans).map(([key, plan]) => subscriptionPlanCardMarkup(key, plan, key === selectedPlan)).join('')}
            </div>
            <div class="hb-subscribe-footer">
                <p>Payment is handled securely through Stripe.</p>
            </div>`;
    }

    function couponCodeEntryMarkup(context) {
        const code = context === 'billing' ? state.billingCouponCode : '';
        const inputAttr = context === 'billing' ? 'data-billing-coupon-code' : 'data-subscribe-coupon-code';
        const buttonAttr = context === 'billing' ? 'data-billing-apply-coupon' : 'data-subscribe-apply-coupon';
        return `
            <div class="hb-coupon-entry">
                <div>
                    <strong>Have a coupon code?</strong>
                    <span>Apply a 6-digit influencer code for free Base access.</span>
                </div>
                <label class="hb-label">Coupon code
                    <input class="hb-input" type="text" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" value="${escapeAttr(code)}" ${inputAttr} ${state.busy || state.billingBusy ? 'disabled' : ''}>
                </label>
                <button class="hb-button-secondary" type="button" ${buttonAttr} ${state.busy || state.billingBusy ? 'disabled' : ''}>${state.busy || state.billingBusy ? 'Applying...' : 'Apply code'}</button>
            </div>`;
    }

    function subscriptionPlanCardMarkup(key, plan, selected) {
        const busy = state.busy && state.selectedPlan === key;
        const billingInterval = normalizedBillingInterval(state.selectedBillingInterval);
        return `
            <article class="hb-subscribe-plan ${plan.popular ? 'hb-subscribe-plan-popular' : ''} ${selected ? 'hb-subscribe-plan-selected' : ''}">
                ${plan.popular ? '<span class="hb-subscribe-badge">Most popular</span>' : ''}
                <div class="hb-subscribe-plan-head">
                    <div>
                        <h2>${escapeHtml(plan.label)}</h2>
                        <p>${escapeHtml(plan.bestFor)}</p>
                    </div>
                    <div class="hb-subscribe-price"><strong>${escapeHtml(planDisplayPrice(plan, billingInterval))}</strong><span>${escapeHtml(planDisplaySuffix(billingInterval))}</span></div>
                </div>
                <div class="hb-subscribe-trial">${escapeHtml(`${subscriptionTrialDays}-day free trial`)}, then billed ${billingInterval === 'yearly' ? 'yearly' : 'monthly'}</div>
                <ul>
                    ${normalizeList(plan.features).map((feature) => `<li>${icons.checkCircle}<span>${escapeHtml(feature)}</span></li>`).join('')}
                </ul>
                <button class="${plan.popular ? 'hb-button' : 'hb-button-secondary'}" type="button" data-subscribe-plan="${escapeAttr(key)}" ${state.busy ? 'disabled' : ''}>
                    ${busy ? '<span class="hb-spinner"></span> Opening payment…' : `Start ${escapeHtml(plan.label)} trial`}
                </button>
            </article>`;
    }

    function normalizedBillingInterval(value) {
        return value === 'yearly' ? 'yearly' : 'monthly';
    }

    function planDisplayPrice(plan, billingInterval = 'monthly') {
        return normalizedBillingInterval(billingInterval) === 'yearly' ? (plan.yearlyPrice || plan.price) : plan.price;
    }

    function planDisplaySuffix(billingInterval = 'monthly') {
        return normalizedBillingInterval(billingInterval) === 'yearly' ? '/yr' : '/mo';
    }

    function billingIntervalToggleMarkup(selected, dataPrefix) {
        const current = normalizedBillingInterval(selected);
        return `
            <div class="hb-billing-interval-toggle" role="group" aria-label="Billing interval">
                <button type="button" class="${current === 'monthly' ? 'active' : ''}" data-${dataPrefix}="monthly" aria-pressed="${current === 'monthly' ? 'true' : 'false'}">Monthly</button>
                <button type="button" class="${current === 'yearly' ? 'active' : ''}" data-${dataPrefix}="yearly" aria-pressed="${current === 'yearly' ? 'true' : 'false'}">Yearly <span>Save over 16%</span></button>
            </div>`;
    }

    function subscriptionConfirmationCopy(liveConfirmed, selectedPlan) {
        const plan = subscriptionPlans[selectedPlan] || subscriptionPlans.premium;
        if (liveConfirmed) {
            return `${plan.label} is active. Your dashboard is ready.`;
        }
        return `Stripe sent you back to HeyBean. ${plan.label} setup is recorded, and the subscription status will update as soon as Stripe confirms it.`;
    }

    function subscriptionConfirmationMarkup(selectedPlan, subscription, liveConfirmed) {
        const plan = subscriptionPlans[selectedPlan] || subscriptionPlans.premium;
        const billingInterval = normalizedBillingInterval(subscription.billing_interval || subscription.billingInterval || state.selectedBillingInterval);
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
                    <div><span>${billingInterval === 'yearly' ? 'Yearly' : 'Monthly'} price</span><strong>${escapeHtml(planDisplayPrice(plan, billingInterval))}${escapeHtml(planDisplaySuffix(billingInterval))}</strong></div>
                    <div><span>Trial</span><strong>${escapeHtml(`${subscriptionTrialDays} days`)}</strong></div>
                    <div><span>Billing cycle</span><strong>${billingInterval === 'yearly' ? 'Yearly' : 'Monthly'}</strong></div>
                </div>
                <div class="hb-subscribe-actions">
                    <button class="hb-button" type="button" data-subscribe-dashboard ${liveConfirmed ? '' : 'disabled'}>${liveConfirmed ? 'Continue to dashboard' : 'Waiting for Stripe confirmation'}</button>
                    <button class="hb-button-secondary" type="button" data-subscribe-refresh ${state.busy ? 'disabled' : ''}>${state.busy ? 'Refreshing…' : 'Refresh subscription status'}</button>
                </div>
            </div>`;
    }

    function subscriptionBillingSummary(plan, trialEndsAt, currentPeriodEnd) {
        const billingInterval = normalizedBillingInterval(state.selectedBillingInterval);
        if (trialEndsAt) return `${plan.label} starts with a free trial through ${formatDateTime(trialEndsAt)}. After that, billing continues ${billingInterval} until canceled.`;
        if (currentPeriodEnd) return `${plan.label} is billed ${billingInterval}. Your current billing cycle renews around ${formatDateTime(currentPeriodEnd)}.`;
        return `${plan.label} starts with a ${subscriptionTrialDays}-day free trial. Billing begins on day ${subscriptionTrialDays + 1} and continues ${billingInterval} until canceled.`;
    }

    function signedInMarkup() {
        const criticalTasks = criticalTasksForToday();
        const criticalReminders = criticalRemindersForToday();
        const criticalEvents = criticalEventsForToday();
        const showAdd = ['today', 'tasks', 'reminders', 'notes'].includes(state.selected);
        const now = new Date();
        return `
            <div class="hb-app">
                ${betaBannerMarkup()}
                <header class="hb-topbar">
                    ${beanPresenceMarkup()}
                    <span class="hb-spacer"></span>
                    <div class="hb-topbar-date-line" data-tour-target="calendar-controls">
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
                <main class="hb-main ${state.selected === 'today' ? 'hb-main-today' : ''} ${['tasks', 'reminders'].includes(state.selected) ? 'hb-main-board' : ''} ${state.selected === 'notes' ? 'hb-main-notes' : ''} ${state.selected === 'admin' ? 'hb-main-admin' : ''}">
                    ${appPanelMarkup()}
                </main>
                ${bottomMenuMarkup()}
                ${onboardingTourMarkup()}
            </div>`;
    }

    function betaBannerMarkup() {
        if (!userIsEarlyAccess()) return '';

        return `<button class="hb-beta-banner" type="button" data-open-issue-report>You are in our Beta testing phase. If you have any issues, please report them here.</button>`;
    }

    function beanPresenceMarkup() {
        if (shouldUseConnectedSignupBeanPresence()) return '';
        const mode = state.bean.mode || 'privacy';
        const label = state.bean.statusText || (mode === 'privacy' ? 'Privacy mode' : 'Listening for “Hey Bean”');
        const pressed = mode !== 'privacy';
        const panelOpen = state.bean.panelOpen;
        return `
            <div class="hb-bean-presence hb-bean-${escapeAttr(mode)} ${panelOpen ? 'hb-bean-presence-open' : ''}">
                <span class="hb-bean-ring" aria-hidden="true"></span>
                <div class="hb-bean-summary">
                    <button class="hb-bean-button" type="button" data-bean-toggle aria-pressed="${pressed}" aria-label="${pressed ? 'Turn Bean privacy mode on' : 'Listen for Hey Bean'}" title="${pressed ? 'Bean is listening locally for Hey Bean' : 'Privacy mode'}">
                        <img src="${escapeAttr(logoUrl)}" alt="Bean">
                    </button>
                    <span class="hb-bean-status" title="${escapeAttr(label)}">${escapeHtml(label)}</span>
                    <button class="hb-bean-panel-toggle" type="button" data-bean-panel aria-expanded="${panelOpen}" aria-controls="hb-bean-chat" aria-label="${panelOpen ? 'Collapse Bean chat' : 'Expand Bean chat'}">
                        <svg class="hb-bean-panel-caret" viewBox="0 0 12 8" aria-hidden="true" focusable="false">
                            <path d="M1.5 1.5 6 6l4.5-4.5"></path>
                        </svg>
                    </button>
                </div>
                ${panelOpen ? beanPanelMarkup() : ''}
            </div>`;
    }

    function shouldUseConnectedSignupBeanPresence() {
        return Boolean(state.signupPaywallDeferred || state.onboardingTourActive || ['post-signup-bean-choice', 'post-tour-first-action'].includes(state.modal?.type));
    }

    function beanPanelMarkup() {
        const messages = normalizeList(state.bean.messages);
        return `
            <aside class="hb-bean-panel" id="hb-bean-chat" aria-label="Bean assistant">
                <div class="hb-bean-chat-log" aria-live="polite">
                    ${messages.length ? messages.map(beanMessageMarkup).join('') : '<div class="hb-empty hb-surface-soft">Ask Bean anything…</div>'}
                </div>
                <form class="hb-bean-chat-form" data-bean-chat-form>
                    <input type="text" data-bean-input placeholder="Ask Bean anything…" value="${escapeAttr(state.bean.input)}" ${state.bean.busy ? 'disabled' : ''}>
                    <button class="hb-button" type="submit" ${state.bean.busy ? 'disabled' : ''}>${state.bean.busy ? 'Working…' : 'Send'}</button>
                </form>
            </aside>`;
    }

    function beanMessageMarkup(message) {
        const role = String(message.role || '').toLowerCase() === 'user' ? 'user' : 'assistant';
        return `<div class="hb-bean-message hb-bean-message-${role}"><strong>${role === 'user' ? 'You' : 'Bean'}</strong><span>${escapeHtml(message.content || '')}</span></div>`;
    }

    function beanActivityMarkup(event) {
        const details = beanActivityDetails(event);
        return `<div class="hb-bean-activity-row"><small>${escapeHtml(formatActivityTime(event.created_at || event.createdAt))}</small><span>${escapeHtml(event.label || event.type || 'Bean activity')}${details ? `<em>${escapeHtml(details)}</em>` : ''}</span></div>`;
    }

    function beanActivityDetails(event) {
        const details = event?.payload?.progress?.details || {};
        const parts = [];
        if (details.provider) parts.push(`provider ${details.provider}`);
        if (details.source_count !== undefined && details.source_count !== null) parts.push(`${details.source_count} sources`);
        if (details.confidence) parts.push(`confidence ${details.confidence}`);
        if (details.title) parts.push(details.title);
        if (details.total_count !== undefined && details.total_count !== null) parts.push(`total ${details.total_count}`);
        if (details.returned_count !== undefined && details.returned_count !== null) parts.push(`shown ${details.returned_count}`);
        return parts.join(' · ');
    }

    function beanEventStatusText(event, fallback = '') {
        return event?.payload?.progress?.status_text || event?.payload?.progress?.label || event?.label || fallback;
    }

    function beanIdleMode() {
        return localStorage.getItem('heybean.bean.privacy') === 'listening' ? 'wake_listening' : 'privacy';
    }

    function beanIdleStatusText() {
        return beanIdleMode() === 'privacy' ? 'Privacy mode' : 'Listening locally for “Hey Bean”';
    }

    function setBeanIdleStatus() {
        state.bean.mode = beanIdleMode();
        state.bean.statusText = beanIdleStatusText();
    }

    function beanConfirmationMarkup(confirmation) {
        return `<div class="hb-bean-confirmation"><span>${escapeHtml(confirmation.summary || 'Please confirm this action.')}</span><button class="hb-button-secondary" type="button" data-bean-confirm="${escapeAttr(confirmation.id)}">Confirm</button></div>`;
    }

    function formatActivityTime(value) {
        if (!value) return '';
        const date = new Date(value);
        return Number.isNaN(date.getTime()) ? '' : date.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
    }

    function appPanelMarkup() {
        if (state.selected === 'settings') {
            return `<div class="hb-shell">${settingsMarkup()}</div>`;
        }
        if (state.selected === 'notes') {
            ensureSelectedNote();
            return notesMarkup();
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
                    ${commandCenterMarkup()}
                </aside>` : ''}
            </div>`;
    }

    function dashboardLoadingMarkup(label) {
        return `<div class="hb-inline-loading hb-surface-soft" role="status" aria-live="polite"><span class="hb-spinner hb-spinner-tiny" aria-hidden="true"></span><span>${escapeHtml(label)}</span></div>`;
    }

    function todayMarkup() {
        const selected = parseLocalDate(state.selectedDay);
        const visibleDays = visibleCalendarDays(selected);
        const loadingCalendar = state.dashboardDataLoading && !state.calendar.length;
        return `
            <section class="hb-card hb-card-pad hb-calendar-card">
                <div class="hb-calendar">
                    ${loadingCalendar ? dashboardLoadingMarkup('Loading calendar...') : ''}
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
            <section class="hb-card-pad hb-board-card" data-tour-target="tasks-view">
                <header class="hb-board-heading"><h2>Tasks</h2></header>
                <div class="hb-tabs">
                    <button class="hb-chip" type="button" data-task-filter="active" aria-pressed="${!completed}">Active</button>
                    <button class="hb-chip" type="button" data-task-filter="done" aria-pressed="${completed}">Done</button>
                </div>
                ${state.dashboardDataLoading && !items.length ? dashboardLoadingMarkup('Loading tasks...') : dayBoardMarkup(items, 'task', completed ? 'No completed tasks' : 'No active tasks')}
            </section>`;
    }

    function remindersMarkup() {
        const completed = state.reminderFilter === 'completed';
        const status = completed ? 'completed' : 'scheduled';
        const items = state.reminders.filter((reminder) => reminder?.status === status);
        return `
            <section class="hb-card-pad hb-board-card" data-tour-target="reminders-view">
                <header class="hb-board-heading"><h2>Reminders</h2></header>
                <div class="hb-tabs">
                    <button class="hb-chip" type="button" data-reminder-filter="scheduled" aria-pressed="${!completed}">Active</button>
                    <button class="hb-chip" type="button" data-reminder-filter="completed" aria-pressed="${completed}">Done</button>
                </div>
                ${state.dashboardDataLoading && !items.length ? dashboardLoadingMarkup('Loading reminders...') : dayBoardMarkup(items, 'reminder', completed ? 'No completed reminders' : 'No scheduled reminders')}
            </section>`;
    }

    function planLimitUpgradeMarkup(message) {
        return `
            <section class="hb-card hb-card-pad hb-board-card">
                ${sectionTitle(icons.notes, 'Upgrade to keep going', message)}
                <a class="hb-button" href="/pricing">View plans</a>
            </section>`;
    }

    function notesMarkup() {
        if (!notesEnabled()) {
            return planLimitUpgradeMarkup('Notes are available on this plan after upgrading.');
        }

        const folders = normalizeNoteFolders(state.noteFolders);
        const notes = filteredNotes();
        const selected = selectedNote();
        const sections = noteListSections(notes);
        const detailOpen = state.notesDetailOpen && selected;
        return `
            <section class="hb-notes-app ${detailOpen ? 'hb-notes-detail-open' : ''}" aria-label="Notes" data-tour-target="notes-view">
                <aside class="hb-notes-folders">
                    <div class="hb-notes-sidebar-title">
                        <strong>Folders</strong>
                        <div class="hb-notes-sidebar-actions">
                            <button class="hb-note-sidebar-action" type="button" data-toggle-note-folder-edit aria-pressed="${state.noteFoldersEditing}" aria-label="${state.noteFoldersEditing ? 'Done editing folders' : 'Edit folders'}" title="${state.noteFoldersEditing ? 'Done' : 'Edit folders'}">${state.noteFoldersEditing ? icons.checkCircle : icons.edit}</button>
                            <button class="hb-note-sidebar-action hb-note-sidebar-add" type="button" data-create-note-folder aria-label="New folder" title="New folder">${icons.add}</button>
                        </div>
                    </div>
                    ${noteFolderButtonMarkup('all', 'All Notes', state.notes.length, icons.notes)}
                    ${noteFolderButtonMarkup('pinned', 'Pinned', state.notes.filter((note) => note.is_pinned || note.isPinned).length, icons.pin)}
                    <div class="hb-note-user-folders ${state.noteFoldersEditing ? 'hb-note-user-folders-editing' : ''}" data-note-folder-list>
                        ${folders.map((folder) => noteFolderButtonMarkup(String(folder.id), folder.name, state.notes.filter((note) => String(note.note_folder_id || note.noteFolderId || '') === String(folder.id)).length, icons.notes, folder)).join('')}
                    </div>
                </aside>
                <aside class="hb-notes-list-pane">
                    <div class="hb-notes-list-heading">
                        <details class="hb-notes-list-options">
                            <summary aria-label="Notes options" title="Notes options">${icons.moreVertical}</summary>
                            <div class="hb-notes-list-options-popover">
                                <strong>Folders</strong>
                                ${noteListOptionButton('all', 'All Notes', state.notes.length)}
                                ${noteListOptionButton('pinned', 'Pinned', state.notes.filter((note) => note.is_pinned || note.isPinned).length)}
                                ${folders.map((folder) => noteListOptionButton(String(folder.id), folder.name, state.notes.filter((note) => String(note.note_folder_id || note.noteFolderId || '') === String(folder.id)).length)).join('')}
                                <span class="hb-note-list-options-break" aria-hidden="true"></span>
                                <strong>Sort</strong>
                                <button class="hb-note-list-option" type="button" data-note-sort="recent" aria-pressed="${state.notesSort !== 'title'}"><span>Most recently edited</span></button>
                                <button class="hb-note-list-option" type="button" data-note-sort="title" aria-pressed="${state.notesSort === 'title'}"><span>Title</span></button>
                                <button class="hb-note-list-option hb-note-list-option-create" type="button" data-create-note-folder>${icons.add}<span>New folder</span></button>
                            </div>
                        </details>
                        <div>
                            <strong>${escapeHtml(currentNotesFolderTitle())}</strong>
                            <small>${notes.length} ${notes.length === 1 ? 'note' : 'notes'}</small>
                        </div>
                    </div>
                    <div class="hb-notes-search-row">
                        <input class="hb-notes-search" type="search" data-notes-search placeholder="Search" value="${escapeAttr(state.notesSearch)}">
                        <button class="hb-icon-button hb-notes-new-button" type="button" data-create-note aria-label="New note" title="New note">${icons.add}</button>
                    </div>
                    <div class="hb-notes-list" role="listbox" aria-label="Notes list">
                        ${sections.length ? sections.map(noteListSectionMarkup).join('') : state.dashboardDataLoading ? dashboardLoadingMarkup('Loading notes...') : `<div class="hb-notes-empty">No notes</div>`}
                    </div>
                </aside>
                <article class="hb-notes-editor-pane">
                    ${selected ? noteEditorMarkup(selected) : notesEmptyEditorMarkup()}
                </article>
            </section>`;
    }

    function noteFolderButtonMarkup(id, label, count, icon, folder = null) {
        const active = String(state.selectedNoteFolderId || 'all') === String(id);
        if (folder && state.noteFoldersEditing) {
            return `
                <div class="hb-note-folder-edit-row" data-note-folder-row="${escapeAttr(id)}" draggable="true">
                    <button class="hb-note-folder-drag" type="button" aria-label="${escapeAttr(`Drag ${label || 'folder'}`)}" title="Drag to reorder">${icons.menu}</button>
                    <button class="hb-note-folder ${active ? 'hb-note-folder-active' : ''}" type="button" data-note-folder="${escapeAttr(id)}">
                        <span>${icon}<strong>${escapeHtml(label || 'Folder')}</strong></span>
                        <em>${count}</em>
                    </button>
                    <button class="hb-note-folder-delete-icon" type="button" data-delete-note-folder="${escapeAttr(folder.id)}" aria-label="${escapeAttr(`Delete ${folder.name}`)}" title="${escapeAttr(`Delete ${folder.name}`)}">${icons.trash}</button>
                </div>`;
        }
        return `
            <button class="hb-note-folder ${active ? 'hb-note-folder-active' : ''}" type="button" data-note-folder="${escapeAttr(id)}">
                <span>${icon}<strong>${escapeHtml(label || 'Folder')}</strong></span>
                <em>${count}</em>
            </button>`;
    }

    function noteListOptionButton(id, label, count) {
        const active = String(state.selectedNoteFolderId || 'all') === String(id);
        return `
            <button class="hb-note-list-option" type="button" data-note-folder="${escapeAttr(id)}" aria-pressed="${active}">
                ${icons.notes}<span>${escapeHtml(label || 'Folder')}</span><em>${count}</em>
            </button>`;
    }

    function noteListSectionMarkup(section) {
        return `
            <section class="hb-note-list-section">
                ${section.title ? `<h3>${escapeHtml(section.title)}</h3>` : ''}
                ${section.notes.map(noteListItemMarkup).join('')}
            </section>`;
    }

    function noteListItemMarkup(note) {
        const active = String(state.selectedNoteId) === String(note.id);
        const text = String(note.plain_text || note.plainText || '').trim();
        const updated = note.updated_at || note.updatedAt;
        return `
            <button class="hb-note-list-item ${active ? 'hb-note-list-item-active' : ''}" type="button" data-select-note="${escapeAttr(note.id)}" role="option" aria-selected="${active}">
                <strong>${escapeHtml(note.title || 'New Note')}</strong>
                <span>${escapeHtml(text || 'No additional text')}</span>
                <small>${note.is_pinned || note.isPinned ? 'Pinned - ' : ''}${escapeHtml(updated ? formatDateOnly(updated) : '')}</small>
            </button>`;
    }

    function noteEditorMarkup(note) {
        const folderId = note.note_folder_id || note.noteFolderId || '';
        const pinned = note.is_pinned || note.isPinned;
        const locked = noteIsLocked(note);
        const metadata = normalizeNoteMetadata(note.metadata);
        const noteWorkspaceIds = linkedWorkspaceIdsForNote(note);
        const activeWorkspaceId = String(note.workspace_id || note.workspaceId || currentWorkspaceId() || '');
        const selectedWorkspaceIds = workspaceAssignmentIds(activeWorkspaceId, noteWorkspaceIds, true);
        return `
            <form class="hb-note-editor ${locked ? 'hb-note-editor-locked' : ''}" data-note-editor="${escapeAttr(note.id)}">
                <div class="hb-note-editor-toolbar">
                    <button class="hb-icon-button hb-note-back-button" type="button" data-note-back aria-label="Back to notes" title="Back to notes">${icons.chevronLeft}</button>
                    <label class="hb-note-folder-select-wrap" aria-label="Folder">
                        <span class="hb-note-folder-select-icon" aria-hidden="true">${icons.folder}</span>
                        <select class="hb-select hb-note-folder-select" name="note_folder_id" ${locked ? 'disabled' : ''}>
                            <option value="">All Notes</option>
                            ${state.noteFolders.map((folder) => `<option value="${escapeAttr(folder.id)}" ${String(folder.id) === String(folderId) ? 'selected' : ''}>${escapeHtml(folder.name)}</option>`).join('')}
                        </select>
                    </label>
                    <span class="hb-note-save-state" aria-live="polite"></span>
                    <details class="hb-note-actions-menu">
                        <summary aria-label="Note actions" title="Note actions">${icons.moreVertical}</summary>
                        <div class="hb-note-actions-popover" role="menu">
                            <button type="button" data-toggle-note-pin="${escapeAttr(note.id)}" role="menuitem">${icons.pin}<span>${pinned ? 'Unpin note' : 'Pin note'}</span></button>
                            <details class="hb-note-workspace-menu">
                                <summary>${icons.spaces}<span>Workspaces</span></summary>
                                <div>
                                    ${noteWorkspaceAssignmentRowsMarkup(workspaces(), selectedWorkspaceIds, activeWorkspaceId)}
                                </div>
                            </details>
                            <button type="button" data-move-note-folder="${escapeAttr(note.id)}" role="menuitem">${icons.notes}<span>Move note</span></button>
                            <button type="button" data-toggle-note-lock="${escapeAttr(note.id)}" role="menuitem">${locked ? icons.unlock : icons.lock}<span>${locked ? 'Unlock note' : 'Lock note'}</span></button>
                            <button class="hb-note-menu-danger" type="button" data-delete-note="${escapeAttr(note.id)}" role="menuitem">Delete note</button>
                        </div>
                    </details>
                </div>
                ${locked ? `<div class="hb-note-lock-banner">${icons.lock}<span>Locked notes are read-only until you unlock them from the note menu.</span></div>` : ''}
                <input class="hb-note-title-input" name="title" value="${escapeAttr(note.title || '')}" placeholder="New Note" autocomplete="off" ${locked ? 'readonly' : ''}>
                <div class="hb-note-markdown-editor" data-note-markdown-editor></div>
                <textarea class="hb-note-markdown-source" data-note-markdown-source hidden>${escapeHtml(note.body_markdown || note.bodyMarkdown || '')}</textarea>
                <input type="hidden" name="metadata" value="${escapeAttr(JSON.stringify(metadata))}">
            </form>`;
    }

    function notesEmptyEditorMarkup() {
        return `
            <div class="hb-notes-empty-editor">
                ${icons.notes}
                <strong>Select or create a note</strong>
            </div>`;
    }

    function adminMarkup() {
        const summary = state.adminDashboardSummary || {};
        const totals = summary.totals || {};
        const issues = state.adminIssueSummary || {};
        const issueReports = normalizeList(issues.issue_reports || issues.issueReports);
        const archivedIssueReports = normalizeList(issues.archived_issue_reports || issues.archivedIssueReports);
        const userGrowth = normalizeList(summary.user_growth || summary.userGrowth);
        const dailyActivity = normalizeList(summary.daily_activity || summary.dailyActivity);
        return `
            <section class="hb-card hb-card-pad hb-admin-panel">
                <div class="hb-section-action-row">
                    ${sectionTitle(icons.activity, 'Project admin', 'Business, traffic, app usage, AI cost, server health, plans, and submitted issue reports')}
                    <button class="hb-button-secondary" type="button" data-refresh-admin ${state.adminLoading ? 'disabled' : ''}>${state.adminLoading ? 'Refreshing...' : 'Refresh'}</button>
                </div>
                ${errorMarkup(state.error)}
                ${state.adminLoading && !state.adminDashboardSummary ? '<div class="hb-empty hb-surface-soft">Loading administration data...</div>' : ''}
                ${adminExecutiveKpisMarkup(summary)}
                ${adminHealthGridMarkup(summary)}
                ${adminDailyActivityChartMarkup(dailyActivity)}
                ${adminUserGrowthChartMarkup(userGrowth)}
                <div class="hb-admin-metrics">
                    ${adminMetricMarkup('Users', totals.users, 'Total accounts')}
                    ${adminMetricMarkup('Workspaces', totals.workspaces, 'Total spaces')}
                    ${adminMetricMarkup('Issue reports', totals.open_issue_reports, 'Open feedback')}
                </div>
                ${adminPlanLimitsMarkup(state.adminPlanLimits)}
                ${adminCouponCodesMarkup(state.adminCoupons)}
                <div class="hb-admin-grid">
                    ${adminIssueReportsBlockMarkup(issueReports, archivedIssueReports)}
                </div>
            </section>`;
    }

    function adminExecutiveKpisMarkup(summary) {
        const business = summary.business || {};
        const traffic = summary.traffic || {};
        const activation = summary.activation || {};
        const aiUsage = summary.ai_usage || summary.aiUsage || {};
        const aiMonth = aiUsage.month || {};
        const server = summary.server || {};
        return `
            <div class="hb-admin-kpi-grid">
                ${adminKpiMarkup('Daily run-rate', formatCurrency(business.daily_revenue_rate || 0), 'From active local subscriptions', 'money')}
                ${adminKpiMarkup('Weekly run-rate', formatCurrency(business.weekly_revenue_rate || 0), 'From active local subscriptions', 'money')}
                ${adminKpiMarkup('MRR', formatCurrency(business.mrr || 0), `${escapeHtml(business.active_paid_subscriptions || 0)} paid subscriptions`, 'money')}
                ${adminKpiMarkup('AI cost month', formatCurrency(aiMonth.estimated_cost_usd || aiMonth.estimatedCostUsd || 0), `${formatCompactNumber(aiMonth.openai?.total_tokens || aiMonth.openai?.totalTokens || 0)} tokens · ${formatDuration(aiMonth.elevenlabs?.voice_seconds || aiMonth.elevenlabs?.voiceSeconds || 0)}`, 'warning')}
                ${adminKpiMarkup('ARR', formatCurrency(business.arr || 0), `${formatPercent(business.month_over_month_growth_rate)} MoM signup growth`, 'money')}
                ${adminKpiMarkup('Active today', activation.active_users_today || 0, `${activation.active_users_7_days || 0} active in 7 days`, 'activity')}
                ${adminKpiMarkup('Visitors today', traffic.unique_visitors_today || 0, `${traffic.page_views_today || 0} page views`, 'traffic')}
                ${adminKpiMarkup('Server', adminServerStatusLabel(server.status), adminServerStatusMeta(server), server.status === 'critical' ? 'danger' : server.status === 'watch' ? 'warning' : 'healthy')}
            </div>`;
    }

    function adminKpiMarkup(label, value, meta, tone = 'neutral') {
        return `
            <article class="hb-admin-kpi hb-admin-kpi-${escapeAttr(tone)}">
                <span>${escapeHtml(label)}</span>
                <strong>${escapeHtml(value)}</strong>
                <small>${escapeHtml(meta)}</small>
            </article>`;
    }

    function adminHealthGridMarkup(summary) {
        return `
            <div class="hb-admin-health-grid">
                ${adminBusinessHealthMarkup(summary.business || {})}
                ${adminTrafficHealthMarkup(summary.traffic || {})}
                ${adminActivationHealthMarkup(summary.activation || {})}
                ${adminBeanQualityHealthMarkup(summary.bean_quality || summary.beanQuality || {})}
                ${adminAiUsageHealthMarkup(summary.ai_usage || summary.aiUsage || {})}
                ${adminAppUsageHealthMarkup(summary.app_usage || summary.appUsage || {})}
                ${adminServerHealthMarkup(summary.server || {})}
            </div>`;
    }

    function adminBusinessHealthMarkup(business) {
        const mix = business.subscription_mix || business.subscriptionMix || {};
        return `
            <section class="hb-admin-health-card">
                <div class="hb-admin-health-head">
                    <strong>Business</strong>
                    <mark class="hb-admin-status hb-admin-status-ok">${escapeHtml(formatPercent(business.month_over_month_growth_rate))} MoM</mark>
                </div>
                <div class="hb-admin-health-metrics">
                    ${adminHealthMetricMarkup('Paid', business.active_paid_subscriptions || business.activePaidSubscriptions || 0)}
                    ${adminHealthMetricMarkup('Trials', business.trialing_subscriptions || business.trialingSubscriptions || 0)}
                    ${adminHealthMetricMarkup('Paid signups week', business.new_paid_week || business.newPaidWeek || 0)}
                    ${adminHealthMetricMarkup('Base/Prem/Pro', `${mix.base || 0}/${mix.premium || 0}/${mix.pro || 0}`)}
                </div>
            </section>`;
    }

    function adminTrafficHealthMarkup(traffic) {
        const pages = normalizeList(traffic.top_pages || traffic.topPages);
        const sources = normalizeList(traffic.top_sources || traffic.topSources);
        return `
            <section class="hb-admin-health-card">
                <div class="hb-admin-health-head">
                    <strong>Traffic</strong>
                    <small>${escapeHtml(traffic.page_views_month || traffic.pageViewsMonth || 0)} views this month</small>
                </div>
                <div class="hb-admin-health-metrics">
                    ${adminHealthMetricMarkup('Visitors week', traffic.unique_visitors_week || traffic.uniqueVisitorsWeek || 0)}
                    ${adminHealthMetricMarkup('Signups week', traffic.signups_week || traffic.signupsWeek || 0)}
                    ${adminHealthMetricMarkup('Early access', traffic.early_access_requests_month || traffic.earlyAccessRequestsMonth || 0)}
                </div>
                <div class="hb-admin-mini-list">
                    ${(pages.length ? pages : [{ path: 'No page data yet', views: 0 }]).slice(0, 4).map((page) => `<span><strong>${escapeHtml(page.path || 'Unknown')}</strong><small>${escapeHtml(page.views || 0)} views</small></span>`).join('')}
                </div>
                <div class="hb-admin-mini-list hb-admin-source-list">
                    ${(sources.length ? sources : [{ source: 'direct', views: 0 }]).slice(0, 3).map((source) => `<span><strong>${escapeHtml(source.source || 'direct')}</strong><small>${escapeHtml(source.visitors || 0)} visitors</small></span>`).join('')}
                </div>
            </section>`;
    }

    function adminActivationHealthMarkup(activation) {
        return `
            <section class="hb-admin-health-card">
                <div class="hb-admin-health-head">
                    <strong>Activation and retention</strong>
                    <small>${escapeHtml(activation.total_app_users || activation.totalAppUsers || 0)} non-admin users</small>
                </div>
                <div class="hb-admin-health-metrics">
                    ${adminHealthMetricMarkup('Active 30d', activation.active_users_30_days || activation.activeUsers30Days || 0)}
                    ${adminHealthMetricMarkup('Inactive 3d', activation.inactive_users_3_days || activation.inactiveUsers3Days || 0)}
                    ${adminHealthMetricMarkup('Inactive 10d', activation.inactive_users_10_days || activation.inactiveUsers10Days || 0)}
                    ${adminHealthMetricMarkup('Inactive 30d', activation.inactive_users_30_days || activation.inactiveUsers30Days || 0)}
                    ${adminHealthMetricMarkup('Verified', activation.verified_users || activation.verifiedUsers || 0)}
                    ${adminHealthMetricMarkup('Onboarded', activation.onboarded_users || activation.onboardedUsers || 0)}
                </div>
            </section>`;
    }

    function adminBeanQualityHealthMarkup(beanQuality) {
        const flags = normalizeList(beanQuality.top_quality_flags || beanQuality.topQualityFlags);
        const recent = normalizeList(beanQuality.recent_flagged_runs || beanQuality.recentFlaggedRuns);
        const status = beanQuality.status || 'healthy';
        const score = beanQuality.score_24h ?? beanQuality.score24h;
        return `
            <section class="hb-admin-health-card">
                <div class="hb-admin-health-head">
                    <strong>Bean Quality</strong>
                    <mark class="hb-admin-status ${status === 'watch' ? 'hb-admin-status-warning' : 'hb-admin-status-ok'}">${escapeHtml(status === 'watch' ? 'Watch' : 'Healthy')}</mark>
                </div>
                <div class="hb-admin-health-metrics">
                    ${adminHealthMetricMarkup('24h score', score == null ? 'n/a' : `${score}%`)}
                    ${adminHealthMetricMarkup('Traces', beanQuality.traces_24h || beanQuality.traces24h || 0)}
                    ${adminHealthMetricMarkup('Flagged', beanQuality.flagged_24h || beanQuality.flagged24h || 0)}
                    ${adminHealthMetricMarkup('Voice', beanQuality.voice_traces_24h || beanQuality.voiceTraces24h || 0)}
                    ${adminHealthMetricMarkup('Avg latency', beanQuality.average_latency_ms_24h || beanQuality.averageLatencyMs24h ? `${beanQuality.average_latency_ms_24h || beanQuality.averageLatencyMs24h}ms` : 'n/a')}
                </div>
                <div class="hb-admin-mini-list">
                    ${(flags.length ? flags : ['No quality flags in the last 24h']).slice(0, 4).map((flag) => `<span><strong>${escapeHtml(String(flag).replaceAll('_', ' '))}</strong><small>${escapeHtml(flag === 'No quality flags in the last 24h' ? 'clean' : 'flag')}</small></span>`).join('')}
                </div>
                ${recent.length ? `<div class="hb-admin-signal-list">${recent.slice(0, 3).map((run) => `<span class="hb-admin-signal hb-admin-signal-warning">#${escapeHtml(run.bean_run_id || '?')} ${escapeHtml(normalizeList(run.quality_flags || run.qualityFlags).join(', '))}</span>`).join('')}</div>` : ''}
            </section>`;
    }

    function adminAiUsageHealthMarkup(aiUsage) {
        const today = aiUsage.today || {};
        const month = aiUsage.month || {};
        const assumptions = aiUsage.pricing_assumptions || aiUsage.pricingAssumptions || {};
        const openAiMonth = month.openai || {};
        const elevenMonth = month.elevenlabs || {};
        const productAppMonth = month.product_app || month.productApp || month.segments?.product_app || month.segments?.productApp || {};
        const landingMonth = month.landing_page || month.landingPage || month.segments?.landing_page || month.segments?.landingPage || {};
        const productVoice = productAppMonth.elevenlabs || {};
        const landingVoice = landingMonth.elevenlabs || {};
        const topUsers = normalizeList(month.top_users || month.topUsers);
        const modelRows = Object.entries(openAiMonth.by_model || openAiMonth.byModel || {}).slice(0, 3);
        return `
            <section class="hb-admin-health-card hb-admin-ai-usage-card">
                <div class="hb-admin-health-head">
                    <strong>AI usage</strong>
                    <small>Estimated OpenAI + ElevenLabs cost</small>
                </div>
                <div class="hb-admin-health-metrics">
                    ${adminHealthMetricMarkup('Today cost', formatCurrency(today.estimated_cost_usd || today.estimatedCostUsd || 0))}
                    ${adminHealthMetricMarkup('Month cost', formatCurrency(month.estimated_cost_usd || month.estimatedCostUsd || 0))}
                    ${adminHealthMetricMarkup('OpenAI tokens', formatCompactNumber(openAiMonth.total_tokens || openAiMonth.totalTokens || 0))}
                    ${adminHealthMetricMarkup('OpenAI requests', openAiMonth.requests || 0)}
                    ${adminHealthMetricMarkup('Voice minutes', formatDuration(elevenMonth.voice_seconds || elevenMonth.voiceSeconds || 0))}
                    ${adminHealthMetricMarkup('App voice', formatDuration(productVoice.voice_seconds || productVoice.voiceSeconds || 0))}
                    ${adminHealthMetricMarkup('Landing voice', formatDuration(landingVoice.voice_seconds || landingVoice.voiceSeconds || 0))}
                    ${adminHealthMetricMarkup('Eleven credits', formatCompactNumber(elevenMonth.credits || 0))}
                </div>
                <p class="hb-admin-health-note">Assumes ElevenLabs ${formatCurrency(assumptions.elevenlabs_agent_cost_per_minute_usd || assumptions.elevenlabsAgentCostPerMinuteUsd || 0.08)}/min, ${formatCompactNumber(assumptions.elevenlabs_agent_credits_per_minute || assumptions.elevenlabsAgentCreditsPerMinute || 0)} credits/min, ${escapeHtml(assumptions.elevenlabs_max_duration_seconds || assumptions.elevenlabsMaxDurationSeconds || 60)}s max sessions, ${escapeHtml(assumptions.elevenlabs_initial_wait_seconds || assumptions.elevenlabsInitialWaitSeconds || 5)}s initial idle, and ${escapeHtml(assumptions.elevenlabs_silence_end_call_seconds || assumptions.elevenlabsSilenceEndCallSeconds || 12)}s follow-up silence. OpenAI token counts are estimated until exact provider usage is exposed by the runtime.</p>
                <div class="hb-admin-mini-list hb-admin-source-list">
                    <span><strong>Product app</strong><small>${escapeHtml(formatCurrency(productAppMonth.estimated_cost_usd || productAppMonth.estimatedCostUsd || 0))} · ${escapeHtml(formatDuration(productVoice.voice_seconds || productVoice.voiceSeconds || 0))} voice</small></span>
                    <span><strong>Landing page</strong><small>${escapeHtml(formatCurrency(landingMonth.estimated_cost_usd || landingMonth.estimatedCostUsd || 0))} · ${escapeHtml(formatDuration(landingVoice.voice_seconds || landingVoice.voiceSeconds || 0))} voice</small></span>
                </div>
                <div class="hb-admin-mini-list">
                    ${(modelRows.length ? modelRows : [['No OpenAI usage yet', { total_tokens: 0, estimated_cost_usd: 0 }]]).map(([model, metrics]) => `<span><strong>${escapeHtml(model)}</strong><small>${escapeHtml(formatCompactNumber(metrics.total_tokens || metrics.totalTokens || 0))} tokens · ${escapeHtml(formatCurrency(metrics.estimated_cost_usd || metrics.estimatedCostUsd || 0))}</small></span>`).join('')}
                </div>
                <div class="hb-admin-mini-list hb-admin-source-list">
                    ${(topUsers.length ? topUsers : [{ email: 'No usage records yet', estimated_cost_usd: 0 }]).slice(0, 4).map((user) => `<span><strong>${escapeHtml(user.email || user.name || `User #${user.user_id || user.userId || '?'}`)}</strong><small>${escapeHtml(formatCurrency(user.estimated_cost_usd || user.estimatedCostUsd || 0))} · ${escapeHtml(formatDuration(user.elevenlabs_voice_seconds || user.elevenlabsVoiceSeconds || 0))}</small></span>`).join('')}
                </div>
            </section>`;
    }

    function adminAppUsageHealthMarkup(appUsage) {
        const today = appUsage.created_today || appUsage.createdToday || {};
        const month = appUsage.created_month || appUsage.createdMonth || {};
        return `
            <section class="hb-admin-health-card">
                <div class="hb-admin-health-head"><strong>App usage</strong></div>
                <div class="hb-admin-health-metrics">
                    ${adminHealthMetricMarkup('Tasks today', today.tasks || 0)}
                    ${adminHealthMetricMarkup('Reminders today', today.reminders || 0)}
                    ${adminHealthMetricMarkup('Events today', today.calendar_events || today.calendarEvents || 0)}
                    ${adminHealthMetricMarkup('Notes today', today.notes || 0)}
                    ${adminHealthMetricMarkup('Workspaces today', today.workspaces || 0)}
                </div>
                <p class="hb-admin-health-note">${escapeHtml(month.tasks || 0)} tasks, ${escapeHtml(month.reminders || 0)} reminders, ${escapeHtml(month.calendar_events || month.calendarEvents || 0)} events, and ${escapeHtml(month.notes || 0)} notes created this month.</p>
            </section>`;
    }

    function adminServerHealthMarkup(server) {
        const disk = server.disk || {};
        const php = server.php || {};
        const queue = server.queue || {};
        const signals = normalizeList(server.signals);
        return `
            <section class="hb-admin-health-card hb-admin-server-card">
                <div class="hb-admin-health-head">
                    <strong>Server health</strong>
                    <mark class="hb-admin-status ${server.status === 'critical' ? 'hb-admin-status-danger' : server.status === 'watch' ? 'hb-admin-status-warning' : 'hb-admin-status-ok'}">${escapeHtml(adminServerStatusLabel(server.status))}</mark>
                </div>
                <div class="hb-admin-health-metrics">
                    ${adminHealthMetricMarkup('Disk used', disk.used_percent == null ? 'n/a' : `${disk.used_percent}%`)}
                    ${adminHealthMetricMarkup('Free disk', formatBytes(disk.free_bytes || 0))}
                    ${adminHealthMetricMarkup('Storage', formatBytes(disk.storage_bytes || 0))}
                    ${adminHealthMetricMarkup('Database', disk.database_bytes == null ? 'n/a' : formatBytes(disk.database_bytes))}
                    ${adminHealthMetricMarkup('PHP peak', php.memory_peak_percent == null ? 'n/a' : `${php.memory_peak_percent}%`)}
                    ${adminHealthMetricMarkup('Queue', queue.pending_jobs == null ? 'n/a' : queue.pending_jobs)}
                </div>
                <div class="hb-admin-signal-list">
                    ${signals.length ? signals.map((signal) => `<span class="hb-admin-signal hb-admin-signal-${escapeAttr(signal.severity || 'warning')}">${escapeHtml(signal.message || '')}</span>`).join('') : '<span class="hb-admin-signal hb-admin-signal-ok">No upgrade signals right now.</span>'}
                </div>
            </section>`;
    }

    function adminHealthMetricMarkup(label, value) {
        return `<span><small>${escapeHtml(label)}</small><strong>${escapeHtml(value)}</strong></span>`;
    }

    function adminDailyActivityChartMarkup(points) {
        if (!points.length) return '';
        const values = points.map((point) => ({
            day: point.day || '',
            pageViews: Number(point.page_views ?? point.pageViews ?? 0),
            signups: Number(point.signups ?? 0),
        }));
        const width = 760;
        const height = 210;
        const padLeft = 44;
        const padRight = 22;
        const padTop = 24;
        const padBottom = 34;
        const max = niceChartMax(Math.max(1, ...values.flatMap((point) => [point.pageViews, point.signups])));
        const xFor = (index) => values.length <= 1 ? padLeft : padLeft + (index / (values.length - 1)) * (width - padLeft - padRight);
        const yFor = (value) => height - padBottom - (value / max) * (height - padTop - padBottom);
        const pathFor = (key) => values.map((point, index) => `${index === 0 ? 'M' : 'L'} ${xFor(index).toFixed(1)} ${yFor(point[key]).toFixed(1)}`).join(' ');
        const ticks = chartYTicks(max, max <= 5 ? max : 4);
        return `
            <div class="hb-admin-growth-card hb-admin-activity-chart-card">
                <div class="hb-admin-growth-header">
                    <div><strong>Daily app pulse</strong><small>Traffic and signups over the selected range</small></div>
                    <div class="hb-admin-chart-legend">
                        <span><i class="hb-legend-traffic"></i>Page views</span>
                        <span><i class="hb-legend-signups"></i>Signups</span>
                    </div>
                </div>
                <svg class="hb-admin-growth-chart" viewBox="0 0 ${width} ${height}" role="img" aria-label="Daily app pulse chart">
                    ${ticks.map((tick) => `
                        <line x1="${padLeft}" y1="${yFor(tick).toFixed(1)}" x2="${width - padRight}" y2="${yFor(tick).toFixed(1)}" class="${tick === 0 ? 'hb-admin-growth-axis' : 'hb-admin-growth-grid'}"></line>
                        <text x="${padLeft - 10}" y="${(yFor(tick) + 4).toFixed(1)}" text-anchor="end" class="hb-admin-growth-label">${escapeHtml(formatCompactNumber(tick))}</text>
                    `).join('')}
                    <path d="${escapeAttr(pathFor('pageViews'))}" class="hb-admin-activity-line hb-admin-activity-traffic"></path>
                    <path d="${escapeAttr(pathFor('signups'))}" class="hb-admin-activity-line hb-admin-activity-signups"></path>
                    <text x="${padLeft}" y="${height - 8}" class="hb-admin-growth-label">${escapeHtml(monthDayLabel(values[0]?.day))}</text>
                    <text x="${width - padRight}" y="${height - 8}" text-anchor="end" class="hb-admin-growth-label">${escapeHtml(monthDayLabel(values[values.length - 1]?.day))}</text>
                </svg>
            </div>`;
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
        const rangeLabel = userGrowthRangeLabel(selectedRange);
        return `
            <div class="hb-admin-growth-card">
                <div class="hb-admin-growth-header">
                    <div><strong>User growth</strong><small>${escapeHtml(rangeLabel)}, cumulative accounts</small></div>
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
                    <defs><linearGradient id="hb-admin-growth-fill" x1="0" y1="0" x2="0" y2="1"><stop offset="0%" stop-color="var(--hb-accent)" stop-opacity=".24"></stop><stop offset="100%" stop-color="var(--hb-accent)" stop-opacity="0"></stop></linearGradient></defs>
                    ${yTicks.map((tick) => `
                        <line x1="${padLeft}" y1="${yFor(tick).toFixed(1)}" x2="${width - padRight}" y2="${yFor(tick).toFixed(1)}" class="${tick === 0 ? 'hb-admin-growth-axis' : 'hb-admin-growth-grid'}"></line>
                        <text x="${padLeft - 10}" y="${(yFor(tick) + 4).toFixed(1)}" text-anchor="end" class="hb-admin-growth-label">${escapeHtml(formatCompactNumber(tick))}</text>
                    `).join('')}
                    ${area ? `<path d="${escapeAttr(area)}" class="hb-admin-growth-area"></path>` : ''}
                    ${path ? `<path d="${escapeAttr(path)}" class="hb-admin-growth-line"></path>` : ''}
                    ${values.map((point, index) => `<circle cx="${xFor(index).toFixed(1)}" cy="${yFor(point.totalUsers).toFixed(1)}" r="${index === values.length - 1 ? 4.8 : 2.8}" class="hb-admin-growth-dot"><title>${escapeHtml(`${monthDayLabel(point.day)}: ${point.totalUsers} users, +${point.newUsers}`)}</title></circle>`).join('')}
                    <text x="${padLeft}" y="${height - 8}" class="hb-admin-growth-label">${escapeHtml(values[0]?.day ? monthDayLabel(values[0].day) : '')}</text>
                    <text x="${width - padRight}" y="${height - 8}" text-anchor="end" class="hb-admin-growth-label">${escapeHtml(latest.day ? monthDayLabel(latest.day) : '')}</text>
                </svg>
            </div>`;
    }

    function userGrowthRangeButtonMarkup(range, label) {
        const active = (state.adminUserGrowthRange || 'last_30_days') === range;
        return `<button class="hb-admin-growth-range-button" type="button" data-user-growth-range="${escapeAttr(range)}" aria-pressed="${active}">${escapeHtml(label)}</button>`;
    }

    function userGrowthRangeLabel(range) {
        return { today: 'Today', last_7_days: 'Last 7 days', last_30_days: 'Last 30 days', all_time: 'All time' }[range] || 'Last 30 days';
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

    function adminMetricMarkup(label, value, meta) {
        return `<div class="hb-admin-metric"><span>${escapeHtml(label)}</span><strong>${escapeHtml(value ?? 0)}</strong><small>${escapeHtml(meta || '')}</small></div>`;
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
                            <small>Feature gates, workspace limits, and history by tier</small>
                        </div>
                        <button class="hb-button-secondary" type="submit" ${state.adminLoading ? 'disabled' : ''}>Save plan limits</button>
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

    function adminCouponCodesMarkup(couponsPayload) {
        const coupons = normalizeList(couponsPayload);
        return `
            <section class="hb-admin-settings hb-admin-coupons" data-admin-coupons-panel>
                <div class="hb-section-action-row">
                    <div>
                        <strong>Influencer coupon codes</strong>
                        <small>Create one-time 6-digit codes for free Base access</small>
                    </div>
                    <span class="hb-item-meta">${coupons.length} active code${coupons.length === 1 ? '' : 's'}</span>
                </div>
                <form class="hb-admin-coupon-form" data-admin-coupon-form>
                    <label><span>Code</span><input class="hb-input" name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" placeholder="Random"></label>
                    <label><span>Months free Base</span><input class="hb-input" type="number" min="1" max="60" name="months_free_base" value="1" required></label>
                    <button class="hb-button-secondary" type="submit" ${state.adminLoading ? 'disabled' : ''}>Create code</button>
                </form>
                <div class="hb-admin-coupon-table">
                    <div class="hb-admin-coupon-head"><span>Code</span><span>Credit</span><span>Status</span><span>Redeemed by</span><span>Created</span><span></span></div>
                    ${coupons.map(adminCouponCodeRowMarkup).join('') || '<div class="hb-empty">No coupon codes yet. Create a random code and send it to an influencer.</div>'}
                </div>
            </section>`;
    }

    function adminCouponCodeRowMarkup(coupon) {
        const used = coupon.used === true || Boolean(coupon.redeemed_at || coupon.redeemedAt);
        const redeemer = coupon.redeemer || {};
        const redeemedLabel = redeemer.email || redeemer.name || (used ? 'Used' : 'Unused');
        return `
            <div class="hb-admin-coupon-row">
                <strong>${escapeHtml(coupon.code || '')}</strong>
                <span>${escapeHtml(coupon.months_free_base || coupon.monthsFreeBase || 1)} month${Number(coupon.months_free_base || coupon.monthsFreeBase || 1) === 1 ? '' : 's'}</span>
                <mark class="hb-admin-status ${used ? 'hb-admin-status-ok' : 'hb-admin-status-warning'}">${used ? 'Used' : 'Unused'}</mark>
                <span>${escapeHtml(redeemedLabel)}</span>
                <span>${escapeHtml(formatDateTime(coupon.created_at || coupon.createdAt) || 'Unknown')}</span>
                <button class="hb-admin-mini-action" type="button" data-admin-coupon-delete="${escapeAttr(coupon.id)}" ${state.adminLoading ? 'disabled' : ''}>Delete</button>
            </div>`;
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
                <input type="hidden" name="billing_type" value="monthly">
                <label><span>Monthly rate USD</span><input class="hb-input" type="number" min="0" step="0.01" name="monthly_rate_usd" value="${escapeAttr(customer.monthly_rate_usd ?? customer.monthlyRateUsd ?? '')}"></label>
                ${adminLimitInputsMarkup(limits)}
                <label><span>Notes</span><textarea class="hb-input" name="notes" rows="3">${escapeHtml(customer.notes || '')}</textarea></label>
                <button class="hb-button-secondary" type="submit" ${state.adminLoading ? 'disabled' : ''}>${id ? 'Save enterprise customer' : 'Add enterprise customer'}</button>
            </form>`;
    }

    function adminLimitInputsMarkup(limits = {}) {
        return `
            <label><span>Workspace limit</span><input class="hb-input" type="number" min="0" name="workspace_limit" placeholder="Unlimited" value="${escapeAttr(limitInputValue(limits.workspace_limit ?? limits.workspaceLimit))}"></label>
            <label><span>Calendar limit</span><input class="hb-input" type="number" min="0" name="calendar_connection_limit" placeholder="Unlimited" value="${escapeAttr(limitInputValue(limits.calendar_connection_limit ?? limits.calendarConnectionLimit))}"></label>
            <label><span>Connected account limit</span><input class="hb-input" type="number" min="0" name="connected_account_limit" placeholder="Unlimited" value="${escapeAttr(limitInputValue(limits.connected_account_limit ?? limits.connectedAccountLimit))}"></label>
            <label><span>History days</span><input class="hb-input" type="number" min="0" name="history_days" placeholder="Unlimited" value="${escapeAttr(limitInputValue(limits.history_days ?? limits.historyDays))}"></label>
            <label><span>Note limit</span><input class="hb-input" type="number" min="0" name="note_limit" placeholder="Unlimited" value="${escapeAttr(limitInputValue(limits.note_limit ?? limits.noteLimit))}"></label>
            <div class="hb-admin-switch-grid">
                ${adminSwitchMarkup('recurring_tasks_enabled', 'Recurring tasks', 'Allow recurring tasks for this tier/customer.', Boolean(limits.recurring_tasks_enabled ?? limits.recurringTasksEnabled))}
                ${adminSwitchMarkup('recurring_reminders_enabled', 'Recurring reminders', 'Allow recurring reminders for this tier/customer.', Boolean(limits.recurring_reminders_enabled ?? limits.recurringRemindersEnabled))}
                ${adminSwitchMarkup('recurring_calendar_enabled', 'Recurring calendar events', 'Allow recurring calendar event series.', Boolean(limits.recurring_calendar_enabled ?? limits.recurringCalendarEnabled))}
                ${adminSwitchMarkup('email_reminders_enabled', 'Email reminders', 'Allow reminder delivery by email.', Boolean(limits.email_reminders_enabled ?? limits.emailRemindersEnabled))}
                ${adminSwitchMarkup('notes_enabled', 'Notes', 'Allow Notes and note folders.', Boolean(limits.notes_enabled ?? limits.notesEnabled))}
            </div>`;
    }

    function limitInputValue(value) {
        return value === null || value === undefined ? '' : value;
    }

    function adminSwitchMarkup(name, label, help, enabled) {
        return `
            <label class="hb-admin-switch">
                <input type="checkbox" name="${escapeAttr(name)}" ${enabled ? 'checked' : ''}>
                <span><strong>${escapeHtml(label)}</strong><small>${escapeHtml(help)}</small></span>
            </label>`;
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

    const onboardingTourSteps = [
        {
            key: 'command-center-agenda',
            title: 'Today at a glance',
            caption: "You'll see today's events, tasks, and reminders in one running list.",
            view: 'today',
            selectors: ['[data-tour-target="command-center-agenda"]'],
        },
        {
            key: 'create-menu',
            title: 'Create items',
            caption: 'Use the plus button to create new events, tasks, reminders, or notes from anywhere in the app.',
            view: 'today',
            selectors: ['[data-tour-target="create-menu"] > summary'],
        },
        {
            key: 'calendar-controls',
            title: 'Calendar views',
            caption: 'Calendar buttons at the top help you move between today, day view, and month view without losing your place.',
            view: 'today',
            selectors: ['[data-tour-target="calendar-controls"]'],
        },
        {
            title: 'Tasks',
            caption: 'Tasks are for things you need to complete. Add the details you need, then check each task off when done.',
            view: 'tasks',
            selectors: ['[data-tour-target="tasks-view"]'],
        },
        {
            title: 'Reminders',
            caption: 'Reminders are lightweight nudges. Use them for quick time-based follow-up without cluttering your task list.',
            view: 'reminders',
            selectors: ['[data-tour-target="reminders-view"]'],
        },
        {
            title: 'Notes',
            caption: 'Notes hold plans, lists, and longer writing. Folders keep them organized, and formatting helps structure what matters.',
            view: 'notes',
            selectors: ['[data-tour-target="notes-view"]'],
        },
        {
            title: 'Import your calendar',
            caption: 'Bring in the calendar you already use. Choose Apple, Google, Outlook, Proton, Yahoo, Fastmail, Nextcloud, or any iCal link.',
            view: 'settings',
            selectors: ['[data-tour-target="external-calendar-import"]'],
        },
    ];

    function onboardingTourStep(index = state.onboardingTourStep) {
        return onboardingTourSteps[Math.min(Math.max(Number(index) || 0, 0), onboardingTourSteps.length - 1)];
    }

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
        if (onboardingTourSeen()) return;
        activateOnboardingTourStep(0);
    }

    function closeOnboardingTour() {
        markOnboardingTourSeen();
        state.onboardingTourActive = false;
        state.onboardingTourStep = 0;
        state.modal = { type: 'post-tour-first-action', step: 'choose' };
        window.cancelAnimationFrame(onboardingTourLayoutFrame);
        onboardingTourLayoutFrame = 0;
    }

    function activateOnboardingTourStep(index) {
        const stepIndex = Math.min(Math.max(Number(index) || 0, 0), onboardingTourSteps.length - 1);
        const step = onboardingTourStep(stepIndex);
        state.onboardingTourStep = stepIndex;
        state.onboardingTourActive = true;
        state.selected = step.view;
        clearPlanLimitError();
        if (step.view === 'today') {
            const now = new Date();
            state.selectedDay = dateOnly(now);
            resetCalendarWindow(now);
            state.showMonth = false;
        }
        if (step.view === 'notes') {
            state.notesDetailOpen = false;
        }
    }

    function onboardingTourMarkup() {
        if (!state.onboardingTourActive) return '';
        const step = onboardingTourStep();
        const isLast = state.onboardingTourStep >= onboardingTourSteps.length - 1;
        return `
            <section class="hb-onboarding-tour" data-onboarding-tour-overlay role="dialog" aria-modal="true" aria-live="polite" aria-label="HeyBean tour">
                <div class="hb-onboarding-tour-scrim" data-tour-scrim="top" aria-hidden="true"></div>
                <div class="hb-onboarding-tour-scrim" data-tour-scrim="left" aria-hidden="true"></div>
                <div class="hb-onboarding-tour-scrim" data-tour-scrim="right" aria-hidden="true"></div>
                <div class="hb-onboarding-tour-scrim" data-tour-scrim="bottom" aria-hidden="true"></div>
                <div class="hb-onboarding-tour-highlight" data-tour-highlight aria-hidden="true"></div>
                <article class="hb-onboarding-tour-card">
                    <div class="hb-onboarding-tour-card-head">
                        <strong>${escapeHtml(step.title)}</strong>
                        <span>${escapeHtml(`${state.onboardingTourStep + 1}/${onboardingTourSteps.length}`)}</span>
                    </div>
                    <p>${escapeHtml(step.caption)}</p>
                    <div class="hb-onboarding-tour-actions">
                        <button class="hb-button-ghost" type="button" data-onboarding-tour-skip>Skip</button>
                        <button class="hb-button" type="button" ${isLast ? 'data-onboarding-tour-finish' : 'data-onboarding-tour-next'}>${isLast ? 'Finish' : 'Next'}</button>
                    </div>
                </article>
            </section>`;
    }

    function settingsMarkup() {
        const user = state.user || {};
        const prefs = user.notification_preferences || {};
        const workspaceItems = workspaces();
        const activeWorkspaceId = String(currentWorkspaceId() || '');
        return `
            <section class="hb-card hb-card-pad hb-settings-grid">
                ${sectionTitle(icons.settings, 'Settings')}
                <div class="hb-settings-email-row">
                    <span class="hb-settings-email-icon" aria-hidden="true">${icons.mail}</span>
                    <span class="hb-settings-email-text">${escapeHtml(user.email || '')}</span>
                    <button class="hb-button-ghost hb-settings-email-action" type="button" data-open-profile>Edit</button>
                </div>
                ${errorMarkup(state.error)}
                ${state.notice ? `<div class="hb-success">${escapeHtml(state.notice)}</div>` : ''}
                ${themeSettingsMarkup()}
                ${settingsCategoriesMarkup()}
                <div class="hb-surface-soft hb-card-pad hb-settings-section hb-settings-notifications-card">
                    ${settingsSectionHeader(icons.bell, 'Notifications', 'Choose how reminders can reach you.')}
                    <label class="hb-switch-row"><input type="checkbox" data-pref="reminder_push" ${prefs.reminder_push !== false ? 'checked' : ''}> Reminder push notifications</label>
                    <label class="hb-switch-row"><input type="checkbox" data-pref="reminder_email" ${prefs.reminder_email === true ? 'checked' : ''}> Reminder emails</label>
                </div>
                <div class="hb-surface-soft hb-card-pad hb-settings-section hb-settings-workspaces-card">
                    <div class="hb-settings-header-with-action">
                        ${settingsSectionHeader(icons.spaces, 'Workspaces', 'Personal and shared spaces with separate calendars, tasks, reminders, and settings.')}
                        <button class="hb-workspace-create-action" type="button" data-create-workspace aria-label="Create workspace" title="Create workspace">${icons.add}</button>
                    </div>
                    ${workspaceSwitcherMarkup(workspaceItems, activeWorkspaceId)}
                    <div class="hb-list hb-workspace-list" style="margin-top:10px">${workspaceItems.map((workspace) => {
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
                        <button class="hb-button-secondary" type="button" data-accept-workspace>Accept invite</button>
                    </div>
                </div>
                <div class="hb-surface-soft hb-card-pad hb-settings-section hb-settings-calendar-card">
                    ${googleCalendarMarkup()}
                </div>
                ${externalCalendarImportSettingsMarkup()}
                <div class="hb-surface-soft hb-card-pad hb-settings-section hb-settings-calendar-preferences-card">
                    ${settingsSectionHeader(icons.calendar, 'Calendar preferences', 'Day view visible hours.')}
                    <div class="hb-field-row hb-settings-hour-row" style="margin-top:10px">
                        ${settingsHourSelectMarkup('Start hour', 'startHour', Number(localStorage.getItem('heybean.calendar.startHour') || 6), 0, 23)}
                        ${settingsHourSelectMarkup('End hour', 'endHour', Number(localStorage.getItem('heybean.calendar.endHour') || 22), 1, 24)}
                    </div>
                </div>
                ${billingSettingsMarkup()}
                <div class="hb-card hb-card-pad hb-settings-section hb-settings-account-card">
                    ${settingsSectionHeader(icons.user, 'Account controls', 'Export, sign out, or permanently delete your account.')}
                    <div class="hb-account-actions">
                        <button class="hb-button-secondary" type="button" data-export-account>Export data</button>
                        <button class="hb-button-secondary" type="button" data-logout>Sign out</button>
                        <button class="hb-button-danger-text" type="button" data-delete-account>Delete account</button>
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

    function externalCalendarImportSettingsMarkup() {
        const workspaceName = workspaceDisplayName(findWorkspace(currentWorkspaceId())) || 'current workspace';
        return `
            <div class="hb-surface-soft hb-card-pad hb-settings-section hb-settings-calendar-import-card" data-tour-target="external-calendar-import">
                ${settingsSectionHeader(icons.calendar, 'Import External Calendar', `Paste a public calendar link and import events into ${workspaceName}.`)}
                <p class="hb-item-meta">Use this for Apple, Proton, Yahoo, Fastmail, Nextcloud, or any iCal feed. Google and Outlook can also use connected sync above for ongoing updates.</p>
                <div class="hb-account-actions">
                    <button class="hb-button-secondary" type="button" data-external-calendar-import-open>Import Calendar</button>
                </div>
            </div>`;
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
        const billingInterval = normalizedBillingInterval(subscription.billing_interval || subscription.billingInterval || state.billingPlanInterval);
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
                            ${Object.entries(subscriptionPlans).map(([key, plan]) => `<option value="${escapeAttr(key)}" ${key === selectedPlan ? 'selected' : ''}>${escapeHtml(plan.label)} ${escapeHtml(planDisplayPrice(plan, billingInterval))}${escapeHtml(planDisplaySuffix(billingInterval))}</option>`).join('')}
                        </select>
                    </label>
                    <label class="hb-label">Billing
                        <select class="hb-select" data-billing-interval-select ${state.billingBusy ? 'disabled' : ''}>
                            <option value="monthly" ${billingInterval === 'monthly' ? 'selected' : ''}>Monthly</option>
                            <option value="yearly" ${billingInterval === 'yearly' ? 'selected' : ''}>Yearly - save up to 17%</option>
                        </select>
                    </label>
                    <button class="hb-button" type="button" data-billing-change-plan ${state.billingBusy ? 'disabled' : ''}>${state.billingBusy ? 'Working...' : 'Change plan'}</button>
                </div>
                ${couponCodeEntryMarkup('billing')}
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
            <details class="hb-create-menu" data-create-menu data-tour-target="create-menu">
                <summary class="hb-icon-button hb-topbar-action hb-create-trigger" aria-label="Create new item" title="Create">${icons.add}</summary>
                <div class="hb-create-popover">
                    <button class="hb-overflow-action" type="button" data-open-create="event">${icons.calendar}<span>New event</span></button>
                    <button class="hb-overflow-action" type="button" data-open-create="task">${icons.tasks}<span>New task</span></button>
                    <button class="hb-overflow-action" type="button" data-open-create="reminder">${icons.reminders}<span>New reminder</span></button>
                    <button class="hb-overflow-action" type="button" data-create-note>${icons.notes}<span>New note</span></button>
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
                    ${userIsAdmin() ? `<button class="hb-profile-action hb-profile-nav-action ${state.selected === 'admin' ? 'hb-profile-action-active' : ''}" type="button" data-nav="admin">${icons.activity}<span>Admin monitor</span></button>` : ''}
                    ${workspaceItems.length > 1 ? `<label class="hb-profile-workspace"><span>${icons.spaces}<strong>Workspace</strong></span><select data-top-workspace-select aria-label="Switch workspace">${workspaceItems.map((workspace) => `<option value="${escapeAttr(workspace.id)}" ${String(workspace.id) === String(activeWorkspace?.id) ? 'selected' : ''}>${escapeHtml(workspaceDisplayName(workspace))}</option>`).join('')}</select></label>` : ''}
                    <button class="hb-profile-action" type="button" data-refresh-app ${state.calendarRefreshing ? 'disabled' : ''}>${state.calendarRefreshing ? '<span class="hb-spinner hb-spinner-tiny"></span>' : icons.refresh}<span>Refresh</span></button>
                    <button class="hb-profile-action hb-profile-nav-action ${state.selected === 'today' ? 'hb-profile-action-active' : ''}" type="button" data-nav="today">${icons.calendar}<span>Calendar</span></button>
                    <button class="hb-profile-action hb-profile-nav-action ${state.selected === 'tasks' ? 'hb-profile-action-active' : ''}" type="button" data-nav="tasks">${icons.tasks}<span>Tasks</span></button>
                    <button class="hb-profile-action hb-profile-nav-action ${state.selected === 'reminders' ? 'hb-profile-action-active' : ''}" type="button" data-nav="reminders">${icons.reminders}<span>Reminders</span></button>
                    <button class="hb-profile-action hb-profile-nav-action ${state.selected === 'notes' ? 'hb-profile-action-active' : ''}" type="button" data-nav="notes">${icons.notes}<span>Notes</span></button>
                    <button class="hb-profile-action ${state.selected === 'settings' ? 'hb-profile-action-active' : ''}" type="button" data-nav="settings">${icons.settings}<span>Settings</span></button>
                    <button class="hb-profile-action" type="button" data-logout>${icons.user}<span>Sign out</span></button>
                </div>
            </details>`;
    }

    function commandCenterMarkup() {
        const items = commandCenterAgendaItems();
        const loading = state.dashboardDataLoading && !items.length;
        return `
            <section class="hb-card hb-command-center-card" aria-label="Command center" data-command-center-shell>
                <div class="hb-command-center-agenda" data-tour-target="command-center-agenda">
                    ${loading ? dashboardLoadingMarkup('Loading today...') : commandCenterAgendaMarkup(items)}
                </div>
                ${dailyStickyNoteMarkup()}
            </section>`;
    }

    function dailyStickyNoteMarkup() {
        const date = state.selectedDay;
        const workspaceId = currentWorkspaceId();
        const key = dailyStickyNoteKey(date, workspaceId);
        const loaded = state.dailyStickyNoteLoadedKeys.has(key);
        const loading = state.dailyStickyNoteLoadingKeys.has(key) || !loaded;
        const content = state.dailyStickyNotes.get(key) || '';
        const status = state.dailyStickyNoteStatuses.get(key) || '';
        const dateLabel = dayLabel(parseLocalDate(date));
        return `
            <div class="hb-daily-sticky-note ${loading ? 'hb-daily-sticky-note-loading' : ''}" data-daily-sticky-note-shell>
                <textarea id="hb-daily-sticky-note" data-daily-sticky-note data-sticky-note-key="${escapeAttr(key)}" data-sticky-note-date="${escapeAttr(date)}" data-sticky-note-workspace="${escapeAttr(workspaceId)}" maxlength="12000" aria-label="${escapeAttr(`Sticky note for ${dateLabel}`)}" placeholder="Sticky Note" ${loading ? 'disabled' : ''}>${escapeHtml(content)}</textarea>
                <span class="hb-daily-sticky-note-status ${status ? 'hb-daily-sticky-note-status-visible' : ''} ${status.includes('Couldn’t') ? 'hb-daily-sticky-note-status-error' : ''}" role="status" aria-live="polite" data-daily-sticky-note-status>${escapeHtml(status)}</span>
            </div>`;
    }

    function commandCenterAgendaMarkup(items) {
        const overdueItems = items.filter((item) => item.isOverdue);
        const todayItems = items.filter((item) => !item.isOverdue);
        const overdueMarkup = overdueItems.length
            ? `
                ${commandCenterSectionHeaderMarkup('Overdue')}
                ${overdueItems.map(commandCenterAgendaItemMarkup).join('')}`
            : '';
        const todayMarkup = todayItems.length
            ? todayItems.map(commandCenterAgendaItemMarkup).join('')
            : '<div class="hb-command-center-empty hb-command-center-empty-inline">Nothing else scheduled for today.</div>';
        return `
            <div class="hb-command-center-agenda-list" aria-label="Today and upcoming list">
                ${overdueMarkup}
                ${commandCenterSectionHeaderMarkup(glanceDayLabel(new Date()))}
                ${todayMarkup}
                ${commandCenterGlanceMarkup()}
            </div>`;
    }

    function commandCenterSectionHeaderMarkup(label) {
        return `<div class="hb-glance-day-label hb-command-center-day-label">${escapeHtml(label)}</div>`;
    }

    function commandCenterAgendaItemMarkup(item) {
        const dataAttr = item.kind === 'event'
            ? `data-edit-event="${escapeAttr(item.id)}"`
            : item.kind === 'task'
                ? `data-edit-task="${escapeAttr(item.id)}"`
                : `data-edit-reminder="${escapeAttr(item.id)}"`;
        const notesIcon = item.kind === 'event' && item.hasNotes ? `<span class="hb-command-center-notes" aria-label="Has notes" title="Has notes">${icons.notes}</span>` : '';
        const styleAttr = item.color ? ` style="--hb-command-center-item-color:${escapeAttr(item.color)}"` : '';
        return `
            <button class="hb-command-center-row hb-command-center-row-${escapeAttr(item.kind)}" type="button" ${dataAttr}${styleAttr}>
                <span class="hb-command-center-time">${escapeHtml(item.timeLabel)}</span>
                <span class="hb-command-center-copy">
                    <strong>${criticalTitleMarkup(item, item.title || 'Untitled', 'hb-command-center-title')}</strong>
                    <small>${escapeHtml(item.subtitle || commandCenterKindLabel(item.kind))}</small>
                </span>
                ${notesIcon}
            </button>`;
    }

    function commandCenterGlanceMarkup() {
        const tomorrow = parseLocalDate(dateOnly(addDays(new Date(), 1)));
        const following = parseLocalDate(dateOnly(addDays(new Date(), 2)));
        return `
            <div class="hb-command-center-glance-list" aria-label="Tomorrow and following day">
                ${[tomorrow, following].map(glanceDayMarkup).join('')}
            </div>`;
    }

    function commandCenterAgendaItems() {
        const now = new Date();
        const todayStart = parseLocalDate(dateOnly(now));
        const endOfToday = addMinutes(addDays(todayStart, 1), -1);
        const items = [];

        state.calendar.forEach((event) => {
            if (!eventIntersectsDay(event, todayStart)) return;
            const startValue = event.starts_at || event.startsAt;
            if (!startValue) return;
            const start = parseLocalDate(startValue);
            if (Number.isNaN(start.getTime())) return;
            const allDay = eventAllDay(event);
            const fallbackEnd = allDay ? addDays(todayStart, 1) : start;
            const end = event.ends_at || event.endsAt ? parseLocalDate(event.ends_at || event.endsAt) : fallbackEnd;
            if (!allDay && !Number.isNaN(end.getTime()) && end < now) return;
            items.push({
                id: event.id,
                kind: 'event',
                title: event.title || event.name || 'Untitled event',
                time: allDay ? todayStart : (start < now && end > now ? now : start),
                timeLabel: commandCenterEventTime(event),
                subtitle: eventLocationText(event),
                hasNotes: Boolean(eventNotesText(event)),
                color: itemColor(event),
                isCritical: Boolean(event.is_critical || event.isCritical),
            });
        });

        activeTopLevelTasks().forEach((task) => {
            const dueValue = task.due_at || task.dueAt || '';
            if (!dueValue) return;
            const due = parseLocalDate(dueValue);
            if (Number.isNaN(due.getTime())) return;
            const dueDay = parseLocalDate(dateOnly(due));
            if (dueDay > todayStart) return;
            const dateOnlyDue = wireValueLooksDateOnly(dueValue);
            const overdue = dueDay < todayStart || (!dateOnlyDue && due < now);
            items.push({
                id: task.id,
                kind: 'task',
                title: task.title || task.name || 'Untitled task',
                time: overdue ? due : (dateOnlyDue ? endOfToday : due),
                timeLabel: overdue && dateOnlyDue ? 'Overdue' : (dateOnlyDue ? 'Today' : formatCompactMeridiemTime(due)),
                subtitle: overdue ? 'overdue' : '',
                isOverdue: overdue,
                color: itemColor(task),
                isCritical: taskCritical(task),
            });
        });

        scheduledReminders().forEach((reminder) => {
            const dueValue = reminderDateValue(reminder);
            if (!dueValue) return;
            const due = parseLocalDate(dueValue);
            if (Number.isNaN(due.getTime())) return;
            const dueDay = parseLocalDate(dateOnly(due));
            if (dueDay > todayStart) return;
            const dateOnlyDue = wireValueLooksDateOnly(dueValue);
            const overdue = dueDay < todayStart || (!dateOnlyDue && due < now);
            items.push({
                id: reminder.id,
                kind: 'reminder',
                title: reminder.title || reminder.name || 'Untitled reminder',
                time: overdue ? due : (dateOnlyDue ? endOfToday : due),
                timeLabel: overdue && dateOnlyDue ? 'Overdue' : (dateOnlyDue ? 'Today' : formatCompactMeridiemTime(due)),
                subtitle: overdue ? 'overdue' : '',
                isOverdue: overdue,
                color: itemColor(reminder),
                isCritical: reminderCritical(reminder),
            });
        });

        return items.sort((a, b) => {
            const timeOrder = a.time - b.time;
            if (timeOrder !== 0) return timeOrder;
            const kindOrder = commandCenterKindRank(a.kind) - commandCenterKindRank(b.kind);
            if (kindOrder !== 0) return kindOrder;
            return String(a.title || '').localeCompare(String(b.title || ''));
        });
    }

    function commandCenterKindLabel(kind) {
        return { event: 'Event', task: 'Task', reminder: 'Reminder' }[kind] || 'Item';
    }

    function commandCenterKindRank(kind) {
        return { event: 0, task: 1, reminder: 2 }[kind] ?? 3;
    }

    function wireValueLooksDateOnly(value) {
        return /^\d{4}-\d{2}-\d{2}$/.test(String(value || '').trim());
    }

    function glanceDayMarkup(day) {
        const events = eventsForDay(day);
        return `
            <div class="hb-glance-day ${events.length ? '' : 'hb-glance-day-empty'}">
                ${commandCenterDayHeaderMarkup(day)}
                <div class="hb-glance-events">
                    ${events.length ? events.map((event) => glanceEventMarkup(event)).join('') : '<div class="hb-empty hb-glance-empty">No events</div>'}
                </div>
            </div>`;
    }

    function commandCenterDayHeaderMarkup(day) {
        return commandCenterSectionHeaderMarkup(glanceDayLabel(day));
    }

    function glanceDayLabel(day) {
        const parsed = parseLocalDate(day);
        if (sameDate(parsed, new Date())) return `Today ${monthDayLabel(parsed)}`;
        if (sameDate(parsed, addDays(new Date(), 1))) return `Tomorrow ${monthDayLabel(parsed)}`;
        return `${weekdayShort(parsed)} ${monthDayLabel(parsed)}`;
    }

    function eventMetadata(event = {}) {
        return event?.metadata && typeof event.metadata === 'object' && !Array.isArray(event.metadata) ? event.metadata : {};
    }

    function eventNotesText(event = {}) {
        return String(event?.description || event?.notes || eventMetadata(event).notes || '').trim();
    }

    function eventLocationText(event = {}) {
        return String(event?.location || eventMetadata(event).place_formatted_address || eventMetadata(event).placeFormattedAddress || '').trim();
    }

    function eventTitleText(event = {}) {
        return event.title || event.name || 'Untitled';
    }

    function eventPillIndicatorsMarkup(event = {}, options = {}) {
        if (options.showIndicators === false) return '';
        const showLocation = options.showLocation !== false;
        const showNotes = options.showNotes !== false;
        const indicators = [];
        if (showLocation && eventLocationText(event)) {
            indicators.push(`<span class="hb-event-pill-icon" title="Has location" aria-label="Has location">${icons.pin}</span>`);
        }
        if (showNotes && eventNotesText(event)) {
            indicators.push(`<span class="hb-event-pill-icon" title="Has notes" aria-label="Has notes">${icons.notes}</span>`);
        }
        return indicators.length ? `<span class="hb-event-pill-icons">${indicators.join('')}</span>` : '';
    }

    function eventPillTitleMarkup(event = {}, className = 'hb-event-title', options = {}) {
        return `<span class="${className}">${criticalTitleMarkup(event, eventTitleText(event), 'hb-event-title-inner')}${eventPillIndicatorsMarkup(event, options)}</span>`;
    }

    function glanceEventMarkup(event) {
        return commandCenterAgendaItemMarkup({
            id: event.id,
            kind: 'event',
            title: eventTitleText(event),
            timeLabel: commandCenterEventTime(event),
            subtitle: eventLocationText(event),
            hasNotes: Boolean(eventNotesText(event)),
            color: itemColor(event),
            isCritical: Boolean(event.is_critical || event.isCritical),
        });
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
        const googleConnected = state.googleStatus?.connected === true;
        const outlookConnected = state.outlookStatus?.connected === true;
        return `
            <strong>External Calendar Sync</strong>
            <p class="hb-item-meta">${googleConnected || outlookConnected ? 'Sync pulls selected external calendar events into your calendar. Local events stay local.' : 'Connect Google Calendar or Microsoft Outlook to import events into HeyBean.'}</p>
            ${externalCalendarProviderMarkup('google', 'Google Calendar', state.googleStatus)}
            ${externalCalendarProviderMarkup('outlook', 'Microsoft Outlook', state.outlookStatus)}
            <div class="hb-account-actions">
                <button class="hb-button-secondary" type="button" data-external-calendar-connect>${googleConnected || outlookConnected ? 'Connect another calendar' : 'Connect Calendar'}</button>
            </div>`;
    }

    function externalCalendarProviderMarkup(provider, label, status) {
        const connected = status?.connected === true;
        const calendars = normalizeList(status?.calendars);
        const selected = new Set(normalizeList(status?.selected_calendar_ids));
        const authUrl = provider === 'outlook' ? state.outlookAuthUrl : state.googleAuthUrl;
        return `
            <div class="hb-list hb-google-list">
                <div class="hb-switch-row">
                    <span><strong>${escapeHtml(label)} ${connected ? 'connected' : 'not connected'}</strong><small>${connected && status?.last_synced_at ? `Last sync ${formatDateTime(status.last_synced_at)}` : 'Use Connect Calendar to authorize this provider.'}</small></span>
                </div>
                ${status?.last_error ? `<div class="hb-error">${escapeHtml(status.last_error)}</div>` : ''}
                ${authUrl ? `<div class="hb-account-actions"><button class="hb-button-secondary" type="button" data-external-calendar-action="${provider}:copy">Copy auth link</button><button class="hb-button-secondary" type="button" data-external-calendar-action="${provider}:check">Check connection</button></div>` : ''}
                ${connected && calendars.length ? calendars.map((calendar) => `
                    <label class="hb-switch-row"><input type="checkbox" data-${provider}-calendar value="${escapeAttr(calendar.id)}" ${selected.has(calendar.id) || calendar.selected ? 'checked' : ''}> <span><strong>${escapeHtml(calendar.summary || calendar.name || calendar.id)}</strong><small>${escapeHtml(calendar.access_role || calendar.accessRole || 'reader')}</small></span></label>
                `).join('') : ''}
                ${connected ? `<div class="hb-account-actions"><button class="hb-button-secondary" type="button" data-external-calendar-action="${provider}:sync">Sync now</button><button class="hb-button-ghost" type="button" data-external-calendar-action="${provider}:disconnect">Disconnect</button></div>` : ''}
            </div>`;
    }

    function bottomMenuMarkup() {
        const nav = [
            ['today', 'Calendar', icons.calendar],
            ['tasks', 'Tasks', icons.tasks],
            ['reminders', 'Reminders', icons.reminders],
            ['notes', 'Notes', icons.notes],
        ];
        return `
            <nav class="hb-bottom-menu" aria-label="App navigation">
                <div class="hb-bottom-bar">
                    ${nav.map(navButton).join('')}
                </div>
            </nav>`;
    }

    function topNavMarkup() {
        const nav = [
            ['today', 'Calendar', icons.calendar],
            ['tasks', 'Tasks', icons.tasks],
            ['notes', 'Notes', icons.notes],
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
                        ${tasks.map((task) => criticalDropdownRowMarkup(icons.tasks, task.title || task.name || 'Untitled', criticalTaskSubtitle(task), `critical-task-item-${escapeAttr(task.id)}`, true)).join('')}
                        ${reminders.map((reminder) => criticalDropdownRowMarkup(icons.reminders, reminder.title || reminder.name || 'Untitled', criticalReminderSubtitle(reminder), `critical-reminder-item-${escapeAttr(reminder.id)}`, true)).join('')}
                        ${events.map((event) => criticalDropdownRowMarkup(icons.calendar, event.title || event.name || 'Untitled', criticalEventSubtitle(event), `critical-event-item-${escapeAttr(event.id)}`, true)).join('')}
                    </div>
                </div>
            </details>`;
    }

    function criticalDropdownRowMarkup(icon, title, subtitle = '', key = '', isCritical = false) {
        return `
            <div class="hb-critical-row" ${key ? `data-critical-row="${key}"` : ''}>
                <span class="hb-critical-row-icon" aria-hidden="true">${icon}</span>
                <span class="hb-critical-row-copy">
                    <strong>${criticalTitleMarkup({ isCritical }, title)}</strong>
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
        const days = centeredMonthGridDays(selected);
        const centerIndex = Math.floor(days.length / 2);
        const first = days[0];
        const weekCount = days.length / 7;
        return `
            <div class="hb-month-view">
                <div class="hb-month-grid" style="--hb-month-week-count:${weekCount}" data-month-grid-center="${dateOnly(days[centerIndex])}" aria-label="${escapeAttr(`${calendarRangeLabel(days)} centered calendar grid`)}">
                    ${Array.from({ length: 7 }, (_, index) => `<div class="hb-month-weekday">${weekdayShort(addDays(first, index))}</div>`).join('')}
                    ${days.map((day, index) => monthCellMarkup(day, sameMonth(day, selected), index === centerIndex)).join('')}
                </div>
            </div>`;
    }

    function monthCellMarkup(day, isCurrentMonth = true, isCentered = false) {
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
            <div class="${cellClasses}" ${isCentered ? 'data-month-center-day' : ''}>
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
            <button class="hb-month-all-day-event" type="button" data-edit-event="${event.id}" style="--hb-month-event-color:${escapeAttr(color)};--hb-month-event-bg:${escapeAttr(hexAlpha(color, .14))};--hb-month-event-bg-hover:${escapeAttr(hexAlpha(color, .20))};--hb-month-event-border:${escapeAttr(hexAlpha(color, .26))}">
                ${monthEventInfoMarkup(event)}
            </button>`;
    }

    function monthMultiDayEventMarkup(event, day) {
        const color = itemColor(event);
        return `
            <button class="hb-month-all-day-event hb-month-multi-day-event" type="button" data-edit-event="${event.id}" style="--hb-month-event-color:${escapeAttr(color)};--hb-month-event-bg:${escapeAttr(hexAlpha(color, .14))};--hb-month-event-bg-hover:${escapeAttr(hexAlpha(color, .20))};--hb-month-event-border:${escapeAttr(hexAlpha(color, .26))}">
                ${monthEventInfoMarkup(event)}
            </button>`;
    }

    function monthEventMarkup(event) {
        const color = itemColor(event);
        return `
            <button class="hb-month-event" type="button" data-edit-event="${event.id}" style="--hb-month-event-color:${escapeAttr(color)};--hb-month-event-bg:${escapeAttr(hexAlpha(color, .14))};--hb-month-event-bg-hover:${escapeAttr(hexAlpha(color, .20))};--hb-month-event-border:${escapeAttr(hexAlpha(color, .26))}">
                ${monthEventInfoMarkup(event)}
            </button>`;
    }

    function monthEventInfoMarkup(event) {
        return `
            <span class="hb-month-event-body">
                <span class="hb-month-event-main">
                    ${criticalTitleMarkup(event, eventTitleText(event), 'hb-month-event-title')}
                </span>
            </span>`;
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
            <article class="hb-item hb-item-${kind} ${completed ? 'hb-item-complete' : ''} ${overdue ? 'hb-item-overdue' : ''}" style="--hb-item-color:${escapeAttr(color)}">
                <label class="hb-check"><input type="checkbox" data-toggle-${kind}="${item.id}" ${completed ? 'checked' : ''}></label>
                <button class="hb-item-main" type="button" data-edit-${kind}="${item.id}">
                    <div class="hb-item-title">${criticalTitleMarkup({ isCritical: critical }, item.title || item.name || 'Untitled')}${expandable ? `<span class="hb-task-expand-icon" data-toggle-task-details="${item.id}" aria-label="${expanded ? 'Hide task details' : 'Show task details'}">${expanded ? '▲' : '▼'}</span>` : ''}</div>
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
                ${eventPillTitleMarkup(event)}
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
                <div class="hb-event-time">${escapeHtml(commandCenterEventTime(event))}</div>
                ${eventPillTitleMarkup(event, 'hb-event-title', { showIndicators: false })}
            </button>`;
    }

    function allDayEventMarkup(event) {
        const color = itemColor(event);
        return `<button class="hb-all-day-event" type="button" data-edit-event="${event.id}" style="background:${hexAlpha(color, .12)};border-color:${hexAlpha(color, .30)}">${eventPillTitleMarkup(event, 'hb-all-day-event-title', { showIndicators: false })}</button>`;
    }

    function multiDayEventMarkup(event, day) {
        const color = itemColor(event);
        const time = multiDayEventDayTime(event, day);
        return `
            <button class="hb-multi-day-event" type="button" data-edit-event="${event.id}" style="background:${hexAlpha(color, .12)};border-color:${hexAlpha(color, .30)}">
                ${time ? `<span class="hb-multi-day-event-time">${escapeHtml(time)}</span>` : ''}
                ${eventPillTitleMarkup(event, 'hb-multi-day-event-title', { showIndicators: false })}
            </button>`;
    }

    function criticalStarMarkup(item) {
        return item?.is_critical || item?.isCritical ? '<span class="hb-star hb-critical-star" role="img" aria-label="Critical">★</span>' : '';
    }

    function criticalTitleMarkup(item, title, className = '') {
        const classes = ['hb-critical-title', className].filter(Boolean).join(' ');
        return `<span class="${classes}">${criticalStarMarkup(item)}<span class="hb-critical-title-text">${escapeHtml(title)}</span></span>`;
    }

    function sectionTitle(icon, title, subtitle = '') {
        return `
            <div class="hb-section-title">
                <span class="hb-section-icon">${icon}</span>
                <div><h2>${escapeHtml(title)}</h2>${subtitle ? `<p>${escapeHtml(subtitle)}</p>` : ''}</div>
            </div>`;
    }

    function labelInput(label, name, type, value = '', attrs = '') {
        if (type === 'date' || type === 'datetime-local') {
            return dateTimePickerInputMarkup(label, name, type, value, attrs);
        }
        const stepAttr = (type === 'datetime-local' || type === 'time') && !/\bstep\s*=/.test(attrs)
            ? 'step="300" '
            : '';
        return `<label class="hb-label">${escapeHtml(label)}<input class="hb-input" type="${type}" name="${escapeAttr(name)}" value="${escapeAttr(value)}" placeholder="${escapeAttr(label)}" ${stepAttr}${attrs}></label>`;
    }

    function dateTimePickerInputMarkup(label, name, type, value = '', attrs = '') {
        const mode = type === 'date' ? 'date' : 'datetime-local';
        const cleanValue = normalizeDateTimePickerValue(value, mode);
        const pickerId = `hb-dtp-${name}-${Math.random().toString(36).slice(2, 9)}`;
        return `
            <label class="hb-label hb-date-time-label">${escapeHtml(label)}
                <span class="hb-date-time-picker" data-date-time-picker data-picker-mode="${escapeAttr(mode)}" data-picker-id="${escapeAttr(pickerId)}">
                    <input class="hb-date-time-value" type="hidden" name="${escapeAttr(name)}" value="${escapeAttr(cleanValue)}" data-date-time-value ${attrs}>
                    <button class="hb-date-time-trigger" type="button" data-date-time-trigger aria-expanded="false" aria-controls="${escapeAttr(pickerId)}">
                        <span data-date-time-display>${escapeHtml(dateTimePickerDisplay(cleanValue, mode))}</span>
                    </button>
                    <div class="hb-date-time-popover" id="${escapeAttr(pickerId)}" data-date-time-panel hidden>
                        ${dateTimePickerPanelMarkup(cleanValue, mode)}
                    </div>
                </span>
            </label>`;
    }

    function dateTimePickerPanelMarkup(value = '', mode = 'datetime-local', visibleMonthValue = '') {
        const selected = dateTimePickerDate(value, mode);
        const visible = visibleMonthValue ? parseLocalDate(visibleMonthValue) : new Date(selected.getFullYear(), selected.getMonth(), 1);
        const visibleMonth = new Date(visible.getFullYear(), visible.getMonth(), 1);
        const firstOfMonth = new Date(visibleMonth.getFullYear(), visibleMonth.getMonth(), 1);
        const gridStart = addDays(firstOfMonth, -firstOfMonth.getDay());
        const previousMonth = new Date(visibleMonth.getFullYear(), visibleMonth.getMonth() - 1, 1);
        const nextMonth = new Date(visibleMonth.getFullYear(), visibleMonth.getMonth() + 1, 1);
        const weekdays = ['S', 'M', 'T', 'W', 'T', 'F', 'S'];
        const dayButtons = Array.from({ length: 42 }, (_, index) => {
            const date = addDays(gridStart, index);
            const inMonth = date.getMonth() === visibleMonth.getMonth();
            const selectedDay = sameDate(date, selected);
            const today = sameDate(date, new Date());
            return `<button class="hb-date-time-day ${inMonth ? '' : 'hb-date-time-day-adjacent'} ${selectedDay ? 'hb-date-time-day-selected' : ''} ${today ? 'hb-date-time-day-today' : ''}" type="button" data-date-time-day="${escapeAttr(dateOnly(date))}" aria-pressed="${selectedDay}" aria-label="${escapeAttr(date.toLocaleDateString(undefined, { month: 'long', day: 'numeric', year: 'numeric' }))}${today ? ' today' : ''}">${date.getDate()}</button>`;
        }).join('');

        return `
            <div class="hb-date-time-calendar" data-visible-month="${escapeAttr(dateOnly(visibleMonth))}">
                <div class="hb-date-time-calendar-head">
                    <button class="hb-date-time-nav" type="button" data-date-time-month="${escapeAttr(dateOnly(previousMonth))}" aria-label="Previous month">${icons.chevronLeft || '&lsaquo;'}</button>
                    <strong>${escapeHtml(visibleMonth.toLocaleDateString(undefined, { month: 'long', year: 'numeric' }))}</strong>
                    <button class="hb-date-time-nav" type="button" data-date-time-month="${escapeAttr(dateOnly(nextMonth))}" aria-label="Next month">${icons.chevronRight || '&rsaquo;'}</button>
                </div>
                <div class="hb-date-time-weekdays">${weekdays.map((day) => `<span>${day}</span>`).join('')}</div>
                <div class="hb-date-time-days">${dayButtons}</div>
                ${mode === 'datetime-local' ? dateTimePickerTimeMarkup(selected) : ''}
                <div class="hb-date-time-actions">
                    <button class="hb-button-secondary hb-date-time-done" type="button" data-date-time-done>Done</button>
                </div>
            </div>`;
    }

    function dateTimePickerTimeMarkup(date) {
        const hour12 = date.getHours() % 12 || 12;
        const roundedMinute = Math.round(date.getMinutes() / 5) * 5;
        const minute = roundedMinute >= 60 ? 55 : roundedMinute;
        const meridiem = date.getHours() >= 12 ? 'PM' : 'AM';
        return `
            <div class="hb-date-time-time-row">
                <label>Hour<select class="hb-select" data-date-time-hour>
                    ${Array.from({ length: 12 }, (_, index) => index + 1).map((hour) => `<option value="${hour}" ${hour === hour12 ? 'selected' : ''}>${hour}</option>`).join('')}
                </select></label>
                <label>Minute<select class="hb-select" data-date-time-minute>
                    ${Array.from({ length: 12 }, (_, index) => index * 5).map((value) => `<option value="${value}" ${value === minute ? 'selected' : ''}>${String(value).padStart(2, '0')}</option>`).join('')}
                </select></label>
                <label>AM/PM<select class="hb-select" data-date-time-meridiem>
                    ${['AM', 'PM'].map((value) => `<option value="${value}" ${value === meridiem ? 'selected' : ''}>${value}</option>`).join('')}
                </select></label>
            </div>`;
    }

    function normalizeDateTimePickerValue(value = '', mode = 'datetime-local') {
        if (!value) return '';
        if (mode === 'date') return storedDateOnly(value);
        return toDatetimeLocal(value);
    }

    function dateTimePickerDate(value = '', mode = 'datetime-local') {
        const parsed = value ? parseLocalDate(value) : new Date();
        if (!Number.isNaN(parsed.getTime())) return parsed;
        return new Date();
    }

    function dateTimePickerDisplay(value = '', mode = 'datetime-local') {
        if (!value) return mode === 'date' ? 'Choose date' : 'Choose date and time';
        if (mode === 'date') {
            const date = parseLocalDate(value);
            return Number.isNaN(date.getTime()) ? 'Choose date' : formatDateOnly(date);
        }
        const date = parseLocalDate(value);
        if (Number.isNaN(date.getTime())) return 'Choose date and time';
        return `${formatDateOnly(date)} at ${date.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' })}`;
    }

    function modalMarkup(modal) {
        if (modal.type === 'register-early-access-success') return registerEarlyAccessSuccessModalMarkup();
        if (modal.type === 'issue-report') return issueReportModalMarkup();
        if (modal.type === 'issue-report-success') return issueReportSuccessModalMarkup();
        if (modal.type === 'external-calendar-connect') return externalCalendarConnectModalMarkup();
        if (modal.type === 'external-calendar-import') return externalCalendarImportModalMarkup(modal);
        if (modal.type === 'post-signup-bean-choice') return postSignupBeanChoiceModalMarkup();
        if (modal.type === 'post-tour-first-action') return postTourFirstActionModalMarkup(modal);
        if (modal.type === 'profile') return profileModalMarkup();
        if (modal.type === 'note-create') return noteCreateModalMarkup();
        if (modal.type === 'workspace') return workspaceModalMarkup(modal.mode, modal.workspace);
        if (modal.type === 'categories') return categoriesModalMarkup();
        if (modal.type === 'recurring-delete') return recurringDeleteModalMarkup(modal.item);
        return itemModalMarkup(modal.type, modal.item, modal.parentTask);
    }

    function postSignupBeanChoiceModalMarkup() {
        return `
            <div class="hb-modal-backdrop hb-post-signup-bean-choice-backdrop hb-zero-choice-backdrop" role="dialog" aria-modal="true" aria-labelledby="post-signup-bean-choice-title">
                <section class="hb-post-signup-bean-choice-modal hb-zero-choice-panel">
                    <h2 id="post-signup-bean-choice-title">Alright, your account is created.</h2>
                    <p>Want a quick tour, a guided first action, or do you want to dive straight in?</p>
                    <div class="hb-post-tour-action-grid hb-zero-choice-grid">
                        <button class="hb-post-tour-action-card hb-zero-choice-action" type="button" data-post-signup-tour>
                            <strong>Quick tour</strong>
                            <span>Show me around.</span>
                        </button>
                        <button class="hb-post-tour-action-card hb-zero-choice-action" type="button" data-post-signup-first-action>
                            <strong>First action</strong>
                            <span>Help me do one thing.</span>
                        </button>
                    </div>
                    <button class="hb-zero-choice-skip" type="button" data-post-signup-skip>Dive in</button>
                </section>
            </div>`;
    }

    function postTourFirstActionModalMarkup(modal = {}) {
        const step = modal.step === 'assist' ? 'assist' : 'choose';
        const action = postTourFirstAction(modal.action);
        if (step === 'assist') {
            return `
            <div class="hb-modal-backdrop" role="dialog" aria-modal="true" aria-labelledby="post-tour-action-title">
                <section class="hb-card hb-modal hb-post-tour-first-action-modal">
                    ${sectionTitle(icons.bean || icons.activity, postTourFirstActionTitle(action), 'Want Bean to handle as much as possible, or walk you step by step while you complete it?')}
                    <div class="hb-post-tour-action-grid">
                        <button class="hb-button" type="button" data-post-tour-walkthrough="${escapeAttr(action.key)}">Walk me through it</button>
                        <button class="hb-button-secondary" type="button" data-post-tour-bean-do-it="${escapeAttr(action.key)}">Have Bean do it</button>
                    </div>
                    <button class="hb-zero-choice-skip" type="button" data-post-tour-first-action-skip>Skip</button>
                </section>
            </div>`;
        }
        return `
            <div class="hb-modal-backdrop hb-zero-choice-backdrop hb-post-tour-zero-backdrop" role="dialog" aria-modal="true" aria-labelledby="post-tour-first-action-title">
                <section class="hb-post-tour-first-action-modal hb-zero-choice-panel">
                    <h2 id="post-tour-first-action-title">What do you want to do first?</h2>
                    <p>Pick one starting point. Bean can guide it without adding more setup chrome.</p>
                    <div class="hb-post-tour-action-grid hb-zero-choice-grid">
                        ${postTourFirstActions.map((item) => `
                            <button class="hb-post-tour-action-card hb-zero-choice-action" type="button" data-post-tour-first-action="${escapeAttr(item.key)}">
                                <strong>${escapeHtml(item.title)}</strong>
                                <span>${escapeHtml(item.subtitle)}</span>
                            </button>`).join('')}
                    </div>
                    <button class="hb-zero-choice-skip" type="button" data-post-tour-first-action-skip>Skip</button>
                </section>
            </div>`;
    }

    const postTourFirstActions = [
        {
            key: 'customize_dashboard',
            title: 'Customize dashboard',
            subtitle: 'Theme, notifications, and calendar hours.',
            beanPrompt: 'Help me customize my HeyBean dashboard. Ask one question at a time, then use available dashboard tools or guide me through Settings to set theme, notifications, and calendar hours.',
        },
        {
            key: 'import_calendar',
            title: 'Import a calendar',
            subtitle: 'Bring in Apple, Google, Outlook, Proton, Yahoo, or iCal.',
            beanPrompt: 'Help me import my calendar into HeyBean. Ask what calendar provider or iCal link I use, then either do the import if you have enough information or walk me step by step.',
        },
        {
            key: 'shared_workspace',
            title: 'Create a shared workspace',
            subtitle: 'Set up a household, project, or team space.',
            beanPrompt: 'Help me create a shared workspace in HeyBean. Ask what to name it and who to invite, then create it if possible or walk me step by step.',
        },
    ];

    function postTourFirstAction(key = '') {
        return postTourFirstActions.find((item) => item.key === key) || postTourFirstActions[0];
    }

    function postTourFirstActionTitle(action) {
        if (action.key === 'customize_dashboard') return 'Customize your dashboard';
        if (action.key === 'import_calendar') return 'Import a calendar';
        return 'Create a shared workspace';
    }

    function finishPostTourFirstAction() {
        if (openDeferredSignupPaywall('Choose a plan to continue into your dashboard.')) return;
        state.modal = null;
    }

    async function askBeanToStartPostTourAction(actionKey) {
        const action = postTourFirstAction(actionKey);
        if (openDeferredSignupPaywall(`Choose a plan to continue, then Bean can help you ${postTourFirstActionTitle(action).toLowerCase()}.`)) return;
        state.modal = null;
        state.bean.panelOpen = true;
        state.notice = 'Bean is starting your first action.';
        render();
        await sendBeanMessageContent(action.beanPrompt);
    }

    function walkThroughPostTourAction(actionKey) {
        const action = postTourFirstAction(actionKey);
        if (openDeferredSignupPaywall(`Choose a plan to continue, then Bean can walk you through ${postTourFirstActionTitle(action).toLowerCase()}.`)) return;
        state.modal = null;
        state.error = '';
        if (action.key === 'customize_dashboard') {
            state.selected = 'settings';
            state.notice = 'Start in Settings: pick your theme, notifications, and calendar hours. Bean can stay open if you want guidance.';
        } else if (action.key === 'import_calendar') {
            state.selected = 'settings';
            state.modal = { type: 'external-calendar-import', providerKey: 'apple', title: 'First action: import your calendar' };
        } else {
            state.selected = 'settings';
            state.modal = { type: 'workspace', mode: 'create', firstAction: true };
            state.notice = 'Create the shared workspace, then invite the person you want to plan with.';
        }
    }

    function externalCalendarConnectModalMarkup() {
        return `
            <div class="hb-modal-backdrop" role="dialog" aria-modal="true" aria-label="Connect external calendar">
                <section class="hb-card hb-modal hb-form">
                    <h3>Connect Calendar</h3>
                    <p class="hb-item-meta">Choose a provider. You will sign in with that provider, approve calendar access, then return here and tap Check connection if needed.</p>
                    <button class="hb-button-secondary" type="button" data-external-calendar-provider="google">Google Calendar</button>
                    <button class="hb-button-secondary" type="button" data-external-calendar-provider="outlook">Microsoft Outlook</button>
                    <div class="hb-modal-actions"><button class="hb-button-ghost" type="button" data-close-modal>Cancel</button></div>
                </section>
            </div>`;
    }

    function externalCalendarImportPreset(key = 'apple') {
        return externalCalendarImportPresets.find((provider) => provider.key === key)
            || externalCalendarImportPresets[0];
    }

    function externalCalendarImportModalMarkup(modal = {}) {
        const provider = externalCalendarImportPreset(modal.providerKey);
        const workspaceName = workspaceDisplayName(findWorkspace(currentWorkspaceId())) || 'current workspace';
        return `
            <div class="hb-modal-backdrop" role="dialog" aria-modal="true" aria-label="Import external calendar">
                <form class="hb-card hb-modal hb-form" data-modal-form="external-calendar-import">
                    <h3>${escapeHtml(modal.title || 'Import External Calendar')}</h3>
                    <p class="hb-item-meta">${escapeHtml(provider.description)} Events import into ${escapeHtml(workspaceName)}.</p>
                    ${modal.error ? `<div class="hb-error"><strong>Calendar import failed</strong><span>${escapeHtml(modal.error)}</span></div>` : ''}
                    <label class="hb-label">Calendar app
                        <select class="hb-select" name="providerKey" data-external-calendar-import-provider>
                            ${externalCalendarImportPresets.map((item) => `<option value="${escapeAttr(item.key)}" ${item.key === provider.key ? 'selected' : ''}>${escapeHtml(item.label)}</option>`).join('')}
                        </select>
                    </label>
                    <label class="hb-label">${escapeHtml(provider.linkLabel)}
                        <input class="hb-input" type="url" name="url" placeholder="${escapeAttr(provider.linkHint)}" autocomplete="off" required>
                    </label>
                    <p class="hb-item-meta">Use a public iCal, ICS, or webcal link. HeyBean does not need account access for this import.</p>
                    <div class="hb-modal-actions">
                        <button class="hb-button-secondary" type="button" data-close-modal>Skip for now</button>
                        <button class="hb-button" type="submit">Import ${escapeHtml(provider.label)}</button>
                    </div>
                </form>
            </div>`;
    }

    function registerEarlyAccessSuccessModalMarkup() {
        return `
            <div class="hb-modal-backdrop" role="dialog" aria-modal="true" aria-labelledby="register-success-title">
                <section class="hb-card hb-modal hb-register-success-modal">
                    <div class="hb-register-success-icon" aria-hidden="true">${icons.checkCircle}</div>
                    <h2 id="register-success-title">Your account has been created.</h2>
                    <p>Check your email to verify, then finish plan setup.</p>
                    <div class="hb-modal-actions hb-issue-report-success-actions">
                        <button class="hb-button" type="button" data-register-early-access-home>Done</button>
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
                    ${isEvent ? formSectionMarkup('', '', eventDetailFieldsMarkup(item)) : ''}
                    ${formSectionMarkup(isEvent ? '' : 'Organize', isEvent ? '' : 'Category, color, and workspace', `
                        <div class="hb-field-row hb-compact-field-row">
                            ${categorySelectMarkup(item)}
                            ${itemColorInputMarkup(item)}
                        </div>
                        ${categoryManagerToggleMarkup()}
                        ${!isReminder && !isTask ? criticalToggleMarkup(item) : ''}
                    `)}
                    ${workspaceConnectionsMarkup(kind, item, workspaceId, editing)}
                    ${formSectionMarkup(isEvent ? '' : 'Repeat', isEvent ? '' : 'Make this repeat when it should come back', recurrenceFieldsMarkup(kind, item))}
                    ${isEvent ? eventReminderFieldsMarkup() : ''}
                    <div class="hb-modal-actions">
                        ${editing ? `<button class="hb-button-danger" type="button" data-modal-delete="${kind}" data-id="${item.id}">Delete</button>` : ''}
                        <button class="hb-button-secondary" type="button" data-close-modal>Cancel</button>
                        <button class="hb-button" type="submit" data-modal-save-button>Save</button>
                    </div>
                </form>
            </div>`;
    }

    function formSectionMarkup(title, subtitle, content) {
        const heading = title || subtitle
            ? `<div class="hb-form-section-head">
                    ${title ? `<strong>${escapeHtml(title)}</strong>` : ''}
                    ${subtitle ? `<span>${escapeHtml(subtitle)}</span>` : ''}
                </div>`
            : '';
        return `
            <section class="hb-form-section">
                ${heading}
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
        const metadata = eventMetadata(item);
        const placeId = metadata.place_id || metadata.placeId || '';
        const placeAddress = metadata.place_formatted_address || metadata.placeFormattedAddress || item?.location || '';
        const placeLat = metadata.place_lat ?? metadata.placeLat ?? metadata.latitude ?? '';
        const placeLng = metadata.place_lng ?? metadata.placeLng ?? metadata.longitude ?? '';
        const googleMapsUri = metadata.google_maps_uri || metadata.googleMapsUri || '';
        return `
            <div class="hb-field-row hb-event-detail-grid">
                <div class="hb-location-field" data-event-location-field>
                    ${labelInput('Location', 'location', 'text', item?.location || '', 'autocomplete="off" data-event-location-input')}
                    <input type="hidden" name="placeId" value="${escapeAttr(placeId)}" data-event-place-id>
                    <input type="hidden" name="placeFormattedAddress" value="${escapeAttr(placeAddress)}" data-event-place-address>
                    <input type="hidden" name="placeLat" value="${escapeAttr(placeLat)}" data-event-place-lat>
                    <input type="hidden" name="placeLng" value="${escapeAttr(placeLng)}" data-event-place-lng>
                    <input type="hidden" name="googleMapsUri" value="${escapeAttr(googleMapsUri)}" data-event-google-maps-uri>
                    <div class="hb-location-suggestions" data-location-suggestions hidden></div>
                    <div class="hb-location-status" data-location-status hidden></div>
                    <div class="hb-location-map" data-location-map hidden>
                        <div class="hb-location-map-image" data-location-map-image></div>
                        <div class="hb-location-map-footer">
                            <span data-location-map-address>${escapeHtml(placeAddress)}</span>
                            <button class="hb-button-secondary hb-location-directions" type="button" data-location-directions>${String(state.user?.preferred_map_app || state.user?.preferredMapApp || 'google') === 'apple' ? 'Apple Maps' : 'Google Maps'}</button>
                        </div>
                    </div>
                </div>
                <label class="hb-label hb-event-status-label">Status<select class="hb-select" name="status">
                    ${['scheduled', 'cancelled'].map((status) => `<option value="${status}" ${String(item?.status || 'scheduled') === status ? 'selected' : ''}>${capitalize(status)}</option>`).join('')}
                </select></label>
            </div>
            <label class="hb-label">Notes<textarea class="hb-textarea" name="description" placeholder="Add notes, agenda, links, or anything useful for this event">${escapeHtml(item?.description || '')}</textarea></label>`;
    }

    function eventReminderFieldsMarkup() {
        const options = [
            [0, 'At start time'],
            [5, '5 minutes before'],
            [10, '10 minutes before'],
            [15, '15 minutes before'],
            [30, '30 minutes before'],
            [60, '1 hour before'],
            [120, '2 hours before'],
            [1440, '1 day before'],
        ];
        return formSectionMarkup('', '', `
            <label class="hb-switch-row hb-form-switch">
                <input type="checkbox" name="createEventReminder" data-event-reminder-toggle>
                <span><strong>Create reminder</strong><small>Reminder timing follows this event's repeat pattern.</small></span>
            </label>
            <div data-event-reminder-fields hidden>
                <label class="hb-label">Remind me
                    <select class="hb-select" name="eventReminderMinutesBefore" disabled>
                        ${options.map(([value, label]) => `<option value="${value}" ${value === 15 ? 'selected' : ''}>${escapeHtml(label)}</option>`).join('')}
                    </select>
                </label>
            </div>
        `);
    }

    function eventTimeFieldsMarkup(item = null, when = '', end = '') {
        const allDay = eventAllDay(item);
        const startSource = item?.starts_at || item?.startsAt || when || defaultEventStart();
        const endSource = item?.ends_at || item?.endsAt || end || startSource;
        const startDate = dateOnly(startSource);
        const endDate = dateOnly(endSource);
        return `
            <div class="hb-all-day-toggle">
                <label class="hb-switch-row hb-all-day-checkbox"><input type="checkbox" name="allDay" data-all-day-toggle ${allDay ? 'checked' : ''}> <strong>All day</strong></label>
                <details class="hb-inline-info">
                    <summary aria-label="All day event info" title="All day event info">${icons.infoCircle}</summary>
                    <div class="hb-inline-info-popover" role="tooltip">Use dates instead of specific start and end times.</div>
                </details>
            </div>
            <div class="hb-field-row" data-timed-fields ${allDay ? 'hidden' : ''}>
                ${labelInput('Starts at', 'time', 'datetime-local', when, allDay ? 'disabled' : 'required')}
                ${labelInput('Ends at', 'endsAt', 'datetime-local', end, allDay ? 'disabled' : '')}
            </div>
            <div class="hb-field-row" data-all-day-fields ${allDay ? '' : 'hidden'}>
                ${labelInput('Start date', 'allDayStart', 'date', startDate, allDay ? 'required' : 'disabled')}
                ${labelInput('Ends before', 'allDayEnd', 'date', endDate, allDay ? 'required' : 'disabled')}
            </div>`;
    }

    function categorySelectMarkup(item = null) {
        const current = item?.category || '';
        const categories = categoryOptions(current);
        return `
            <label class="hb-label">Category<select class="hb-select" name="category" data-category-select>
                <option value="" data-category-color="${escapeAttr(themeAccentColor())}">None</option>
                ${categories.map((category) => `<option value="${escapeAttr(category.name)}" data-category-color="${escapeAttr(safeColor(category.color))}" ${category.name === current ? 'selected' : ''}>${escapeHtml(category.name)}</option>`).join('')}
            </select></label>`;
    }

    function itemColorInputMarkup(item = null) {
        const currentCategory = String(item?.category || '').trim();
        return `
            <label class="hb-label" data-no-category-color-field ${currentCategory ? 'hidden' : ''}>Color<input class="hb-input hb-color-input" type="color" name="color" value="${escapeAttr(itemColor(item))}" data-no-category-color-input></label>`;
    }

    function categoryManagerToggleMarkup() {
        return `
            <div class="hb-inline-category-shell">
                <button class="hb-category-manager-trigger" type="button" data-open-categories aria-expanded="false">Manage categories</button>
                <div class="hb-inline-category-manager" data-category-manager hidden>
                    <div class="hb-inline-category-head">
                        <strong>Categories</strong>
                        <span data-inline-category-message></span>
                    </div>
                    <div class="hb-inline-category-create">
                        <label class="hb-label">New category<input class="hb-input" type="text" data-inline-category-name placeholder="Category name"></label>
                        <label class="hb-label">Color<input class="hb-input hb-color-input" type="color" data-inline-category-color value="${escapeAttr(themeAccentColor())}"></label>
                        <button class="hb-button-secondary" type="button" data-inline-category-create>Save</button>
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
        const selectedWorkspaceIds = workspaceAssignmentIds(sourceWorkspaceId, linked, editing);
        return `
            <section class="hb-form-section hb-event-connections hb-workspace-picker" data-workspace-picker>
                <div class="hb-form-section-head"><strong>Workspaces</strong></div>
                <div class="hb-form-section-body">
                <input type="hidden" name="workspaceId" value="${escapeAttr(sourceWorkspaceId)}">
                <div class="hb-option-list hb-workspace-assignment-list" aria-label="Workspaces">
                    ${workspaceAssignmentRowsMarkup(allWorkspaces, selectedWorkspaceIds, sourceWorkspaceId, editing)}
                </div>
                ${kind === 'reminder' ? `<div data-reminder-recipient-options>${reminderRecipientOptionsMarkup(selectedWorkspaceIds, item)}</div>` : ''}
                </div>
            </section>`;
    }

    function workspaceAssignmentIds(sourceWorkspaceId, linked = new Set(), editing = false) {
        return Array.from(new Set([
            editing ? sourceWorkspaceId : personalWorkspaceId(),
            ...Array.from(linked || []),
        ].map(String).filter(Boolean)));
    }

    function workspaceAssignmentRowsMarkup(allWorkspaces, selectedWorkspaceIds = [], sourceWorkspaceId = '', editing = false) {
        const selected = new Set(selectedWorkspaceIds.map(String));
        return allWorkspaces.map((workspace) => {
            const workspaceId = String(workspace.id || '');
            const checked = selected.has(workspaceId);
            const locked = editing && workspaceId === String(sourceWorkspaceId || '');
            const current = workspaceId === String(currentWorkspaceId() || '');
            return `<label class="hb-switch-row"><input type="checkbox" name="workspaceAssignmentIds" value="${escapeAttr(workspace.id)}" ${checked ? 'checked' : ''} ${locked ? 'disabled' : ''}> <span><strong>${escapeHtml(workspaceDisplayName(workspace))}</strong>${current ? '<small class="hb-workspace-current-label">Current workspace</small>' : ''}</span></label>`;
        }).join('') || '<p class="hb-item-meta">No workspaces available.</p>';
    }

    function noteWorkspaceAssignmentRowsMarkup(allWorkspaces, selectedWorkspaceIds, sourceWorkspaceId) {
        const selected = new Set(selectedWorkspaceIds.map(String));
        return allWorkspaces.map((workspace) => {
            const workspaceId = String(workspace.id || '');
            const source = workspaceId === String(sourceWorkspaceId || '');
            const current = workspaceId === String(currentWorkspaceId() || '');
            return `<label><input type="checkbox" ${source ? '' : `data-note-sync-workspace="${escapeAttr(workspace.id)}"`} ${selected.has(workspaceId) ? 'checked' : ''} ${source ? 'disabled' : ''}> <span>${escapeHtml(workspaceDisplayName(workspace))}${current ? ' <small class="hb-workspace-current-label">Current workspace</small>' : ''}</span></label>`;
        }).join('') || '<small>No workspaces available.</small>';
    }

    function noteCreateModalMarkup() {
        const selectedWorkspaceIds = workspaceAssignmentIds('', new Set(), false);
        return `
            <div class="hb-modal-backdrop" role="dialog" aria-modal="true" aria-labelledby="note-create-title">
                <form class="hb-card hb-modal hb-form" data-modal-form="note-create">
                    ${sectionTitle(icons.notes, 'New note', '')}
                    ${labelInput('Title', 'title', 'text', 'New Note', 'required')}
                    <section class="hb-form-section hb-workspace-picker" data-workspace-picker>
                        <div class="hb-form-section-head"><strong>Workspaces</strong></div>
                        <div class="hb-form-section-body">
                            <div class="hb-option-list hb-workspace-assignment-list" aria-label="Workspaces">
                                ${workspaceAssignmentRowsMarkup(workspaces(), selectedWorkspaceIds)}
                            </div>
                        </div>
                    </section>
                    <div class="hb-modal-actions">
                        <button class="hb-button-secondary" type="button" data-close-modal>Cancel</button>
                        <button class="hb-button" type="submit">Create</button>
                    </div>
                </form>
            </div>`;
    }

    function reminderRecipientOptionsMarkup(workspaceIds = [], item = null, selectedByWorkspace = null) {
        const assignmentIds = Array.from(new Set(normalizeList(workspaceIds).map(String).filter(Boolean)));
        const savedSelections = selectedByWorkspace || reminderRecipientsByWorkspace(item);
        const hasSavedSelections = Boolean(item && Object.keys(savedSelections).length);
        const currentUserId = String(state.user?.id || '');
        const groups = assignmentIds.map((workspaceId) => {
            const workspace = findWorkspace(workspaceId);
            const members = workspaceMembers(workspace);
            if (!workspace || !members.length) return '';
            const selected = new Set((savedSelections[workspaceId] || []).map(String));
            return `
                <div class="hb-reminder-recipient-group" data-reminder-recipient-workspace="${escapeAttr(workspaceId)}">
                    <div class="hb-reminder-recipient-group-head">
                        <strong>${escapeHtml(workspaceDisplayName(workspace))}</strong>
                        <small>${escapeHtml(workspaceTypeLabel(workspace))}</small>
                    </div>
                    ${members.map((member) => {
                        const user = member.user || member;
                        const userId = String(user.id || member.user_id || member.userId || '');
                        const checked = hasSavedSelections ? selected.has(userId) : userId === currentUserId;
                        return `<label class="hb-switch-row"><input type="checkbox" name="notificationRecipients" value="${escapeAttr(userId)}" data-recipient-workspace-id="${escapeAttr(workspaceId)}" ${checked ? 'checked' : ''}> <span><strong>${escapeHtml(user.name || user.email || 'Workspace member')}</strong><small>${escapeHtml(user.email || member.role || 'member')}</small></span></label>`;
                    }).join('')}
                </div>`;
        }).filter(Boolean);

        return groups.length ? `<div class="hb-label">Notify
            <div class="hb-option-list hb-reminder-recipient-list">${groups.join('')}</div>
        </div>` : '<p class="hb-item-meta">Add workspace members before assigning reminder notifications.</p>';
    }

    function reminderRecipientsByWorkspace(item = null) {
        const metadata = typeof item?.metadata === 'object' && item?.metadata ? item.metadata : {};
        const source = metadata.notification_recipients_by_workspace || metadata.notificationRecipientsByWorkspace || {};
        const map = {};
        if (source && typeof source === 'object' && !Array.isArray(source)) {
            Object.entries(source).forEach(([workspaceId, ids]) => {
                map[String(workspaceId)] = normalizeList(ids).map(String);
            });
        }
        if (!Object.keys(map).length) {
            const workspaceId = String(item?.workspace_id || item?.workspaceId || currentWorkspaceId() || '');
            const flat = normalizeList(metadata.notification_recipient_user_ids || metadata.notificationRecipientUserIds).map(String);
            if (workspaceId && flat.length) map[workspaceId] = flat;
        }
        return map;
    }

    function workspaceMembers(workspace = {}) {
        return normalizeList(workspace?.memberships || workspace?.members || [])
            .filter((member) => String(member.status || 'active').toLowerCase() === 'active')
            .filter((member) => member.user || member.id || member.user_id || member.userId);
    }

    function profileModalMarkup() {
        const preferredMapApp = String(state.user?.preferred_map_app || state.user?.preferredMapApp || 'google') === 'apple' ? 'apple' : 'google';
        const timezone = userTimezone();
        const detectedTimezone = browserTimezone();
        return `
            <div class="hb-modal-backdrop" role="dialog" aria-modal="true">
                <form class="hb-card hb-modal hb-form" data-modal-form="profile">
                    ${sectionTitle(icons.user, 'Account settings', '')}
                    ${labelInput('Email', 'email', 'email', state.user?.email || '', 'required')}
                    <label class="hb-label">Timezone
                        <input class="hb-input" name="timezone" value="${escapeAttr(timezone)}" placeholder="America/New_York" autocomplete="off" list="hb-timezone-options" required>
                        <datalist id="hb-timezone-options">
                            ${Array.from(new Set([detectedTimezone, 'America/New_York', 'America/Chicago', 'America/Denver', 'America/Los_Angeles', 'UTC'].filter(Boolean))).map((zone) => `<option value="${escapeAttr(zone)}"></option>`).join('')}
                        </datalist>
                        <small>Used as the single source of truth for Bean, reminders, tasks, and calendar times.</small>
                    </label>
                    <label class="hb-label">Preferred maps app<select class="hb-select" name="preferredMapApp">
                        <option value="google" ${preferredMapApp === 'google' ? 'selected' : ''}>Google Maps</option>
                        <option value="apple" ${preferredMapApp === 'apple' ? 'selected' : ''}>Apple Maps</option>
                    </select></label>
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
                    ${sectionTitle(icons.calendar, create ? 'Create Workspace' : rename ? 'Rename household' : invite ? `Invite to ${workspace?.name || 'workspace'}` : 'Accept workspace invitation', '')}
                    ${labelInput(create ? 'Workspace name' : rename ? 'Household name' : invite ? 'Email' : 'Invitation token or link', create || rename ? 'name' : invite ? 'email' : 'token', invite ? 'email' : 'text', rename ? workspace?.name || '' : '', 'required')}
                    <input type="hidden" name="workspaceId" value="${escapeAttr(workspace?.id || '')}">
                    <div class="hb-modal-actions"><button class="hb-button-secondary" type="button" data-close-modal>Cancel</button><button class="hb-button" type="submit">${create || rename ? 'Save' : invite ? 'Invite' : 'Accept'}</button></div>
                </form>
            </div>`;
    }

    function recurrenceFieldsMarkup(kind, item) {
        const recurrence = itemRecurrenceValue(item);
        const recurrenceMeta = recurrenceMetadata(item?.metadata);
        const days = recurrenceDays(item?.metadata);
        const unit = ['days', 'weeks', 'months', 'years'].includes(recurrenceMeta.unit) ? recurrenceMeta.unit : 'days';
        return `
            <label class="hb-label">Recurrence
                <select class="hb-select" name="recurrence" data-recurrence-select>
                    ${recurrenceOptions().map((value) => `<option value="${value}" ${value === recurrence ? 'selected' : ''}>${recurrenceLabel(value)}</option>`).join('')}
                </select>
            </label>
            <div class="hb-tabs hb-recurrence-days" data-recurrence-days ${recurrence === 'specific_days' ? '' : 'hidden'}>
                ${['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'].map((day) => `<label class="hb-chip"><input type="checkbox" name="days" value="${day}" ${days.has(day) ? 'checked' : ''}> ${day.toUpperCase()}</label>`).join('')}
            </div>
            <div class="hb-field-row" data-recurrence-interval ${recurrence === 'interval' ? '' : 'hidden'}>
                ${labelInput('Repeat interval', 'interval', 'number', recurrenceMeta.interval || '', 'min="1"')}
                <label class="hb-label">Interval unit<select class="hb-select" name="unit"><option value="days">Days</option><option value="weeks" ${unit === 'weeks' ? 'selected' : ''}>Weeks</option><option value="months" ${unit === 'months' ? 'selected' : ''}>Months</option><option value="years" ${unit === 'years' ? 'selected' : ''}>Years</option></select></label>
            </div>`;
    }

    function recurrenceOptions() {
        return ['none', 'daily', 'weekly', 'monthly', 'yearly', 'specific_days', 'interval'];
    }

    function itemRecurrenceValue(item = null) {
        if (eventIsGeneratedOccurrence(item)) return 'none';
        const value = Object.prototype.hasOwnProperty.call(item || {}, 'recurrence')
            ? item?.recurrence
            : item?.metadata?.recurrence;
        return recurrenceOptions().includes(value) ? value : 'none';
    }

    function recurrenceMetadata(metadata = {}) {
        return metadata && typeof metadata === 'object' ? metadata : {};
    }

    function recurrenceDays(metadata = {}) {
        const days = Array.isArray(metadata?.days) ? metadata.days : [];
        return new Set(days.filter((day) => ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'].includes(day)));
    }

    function categoriesModalMarkup() {
        return `
            <div class="hb-modal-backdrop" role="dialog" aria-modal="true">
                <section class="hb-card hb-modal">
                    ${sectionTitle(icons.tune, 'Categories', 'Create, recolor, or delete item categories.')}
                    <form class="hb-form hb-category-create" data-modal-form="category-create">
                        <div class="hb-field-row">${labelInput('Name', 'name', 'text', '', 'required')}${labelInput('Color', 'color', 'color', themeAccentColor())}</div>
                        <button class="hb-button" type="submit">Save</button>
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
            const nextMode = button.dataset.authMode;
            state.authMode = nextMode;
            state.error = '';
            state.notice = '';
            if (nextMode === 'register') {
                startGuidedSignup();
                return;
            }
            state.phase = 'signedOut';
            history.pushState({}, '', nextMode === 'forgot' ? '/forgot-password' : '/login');
            render();
        }));
        mount.querySelectorAll('form[data-action="login"], form[data-action="forgot"]').forEach((form) => form.addEventListener('submit', submitAuth));
        const guidedOnboardingForm = mount.querySelector('[data-action="guided-onboarding"]');
        guidedOnboardingForm?.addEventListener('submit', submitGuidedOnboarding);
        guidedOnboardingForm?.querySelector('[name="value"]')?.addEventListener('input', () => dispatchSignupVoiceActivity({ reason: 'typing' }));
        guidedOnboardingForm?.querySelector('[name="value"]')?.addEventListener('focus', () => dispatchSignupVoiceActivity({ reason: 'focus' }));
        mount.querySelector('[data-action="plain-signup"]')?.addEventListener('submit', submitPlainSignup);
        mount.querySelectorAll('[data-guided-theme-mode]').forEach((button) => button.addEventListener('click', () => {
            if (state.phase === 'plainSignup') {
                state.guidedSignupThemeMode = guidedThemeModeFromText(button.dataset.guidedThemeMode || '') || state.guidedSignupThemeMode;
                render();
                return;
            }
            if (guidedSignupInputLocked()) return;
            selectGuidedThemeMode(button.dataset.guidedThemeMode || '');
        }));
        mount.querySelectorAll('[data-plain-signup]').forEach((button) => button.addEventListener('click', startPlainSignup));
        mount.querySelectorAll('[data-dismiss-plan-limit-error]').forEach((button) => button.addEventListener('click', () => {
            state.error = '';
            render();
        }));
    }

    function bindSubscriptionActions() {
        mount.querySelectorAll('[data-subscribe-plan]').forEach((button) => button.addEventListener('click', () => startSubscriptionCheckout(button.dataset.subscribePlan)));
        mount.querySelectorAll('[data-subscribe-billing-interval]').forEach((button) => button.addEventListener('click', () => {
            state.selectedBillingInterval = normalizedBillingInterval(button.dataset.subscribeBillingInterval);
            render();
        }));
        mount.querySelectorAll('[data-subscribe-dashboard]').forEach((button) => button.addEventListener('click', async () => {
            history.pushState({}, '', '/app');
            state.selected = 'today';
            await loadSignedIn();
            startOnboardingTourIfNeeded();
            render();
        }));
        mount.querySelectorAll('[data-subscribe-refresh]').forEach((button) => button.addEventListener('click', refreshSubscriptionStatus));
        mount.querySelectorAll('[data-subscribe-logout]').forEach((button) => button.addEventListener('click', logout));
    }

    function bindBeanActions() {
        mount.querySelector('[data-bean-toggle]')?.addEventListener('click', toggleBeanPrivacyMode);
        mount.querySelector('[data-bean-panel]')?.addEventListener('click', () => {
            if (state.bean.voiceActive && beanAudioPlaybackBlocked) {
                resumeBeanElevenLabsAudioPlayback('panel_click');
            }
            state.bean.panelOpen = !state.bean.panelOpen;
            render();
            if (state.bean.panelOpen) loadBeanActivity().finally(render);
        });
        mount.querySelector('[data-bean-input]')?.addEventListener('input', (event) => {
            state.bean.input = event.currentTarget.value;
        });
        mount.querySelector('[data-bean-chat-form]')?.addEventListener('submit', sendBeanMessage);
        mount.querySelectorAll('[data-bean-confirm]').forEach((button) => button.addEventListener('click', () => approveBeanConfirmation(button.dataset.beanConfirm)));
    }

    function toggleBeanPrivacyMode() {
        setBeanMode(state.bean.mode === 'privacy' ? 'wake_listening' : 'privacy');
    }

    function setBeanMode(mode) {
        state.bean.mode = mode === 'privacy' ? 'privacy' : 'wake_listening';
        localStorage.setItem('heybean.bean.privacy', state.bean.mode === 'privacy' ? 'privacy' : 'listening');
        if (state.bean.mode === 'privacy') {
            stopBeanWakeListening();
            beanRealtimeSessionCache = null;
            beanRealtimeSessionPromise = null;
            if (state.bean.voiceActive || state.bean.voiceConnecting) stopBeanVoiceSession({ keepStatus: true });
            state.bean.statusText = 'Privacy mode';
        } else {
            startBeanWakeListening();
        }
        render();
    }

    async function startBeanWakeListening() {
        if (beanWakeDetector || beanWakeListeningStarting || state.bean.voiceActive || state.bean.voiceConnecting) return;
        beanWakeListeningStarting = true;
        const wakeFactory = resolveBeanWakeFactory();
        if (!wakeFactory?.create) {
            beanWakeListeningStarting = false;
            state.bean.statusText = 'On — local wake is unavailable in this browser';
            state.bean.error = 'This browser does not expose a verified local wake-word detector. Privacy mode is still off, but Bean cannot start from “Hey Bean” until a local detector is available.';
            render();
            return;
        }
        try {
            state.bean.error = '';
            state.bean.statusText = 'Starting local wake listening…';
            beanWakeDetector = await wakeFactory.create({
                phrase: 'Hey Bean',
                onWake: handleBeanWakeDetected,
            });
            await beanWakeDetector?.start?.();
            state.bean.statusText = 'Listening locally for “Hey Bean”';
            prewarmBeanRealtimeSession();
            render();
        } catch (error) {
            beanWakeDetector = null;
            state.bean.error = friendlyError(error, 'start local wake detection');
            state.bean.statusText = 'On — local wake unavailable';
            render();
        } finally {
            beanWakeListeningStarting = false;
        }
    }

    function resolveBeanWakeFactory() {
        if (window.HeyBeanLocalWakeDetector?.create) return window.HeyBeanLocalWakeDetector;
        return browserLocalSpeechWakeFactory();
    }

    function browserLocalSpeechWakeFactory() {
        const Recognition = window.SpeechRecognition;
        if (!Recognition || typeof Recognition.available !== 'function') return null;
        return {
            async create({ phrase, onWake }) {
                const language = 'en-US';
                const options = { langs: [language], processLocally: true };
                let availability = await Recognition.available(options).catch(() => 'unavailable');
                if (availability === 'downloadable' && typeof Recognition.install === 'function') {
                    await Recognition.install(options).catch(() => false);
                    availability = await Recognition.available(options).catch(() => 'unavailable');
                }
                if (availability !== 'available') {
                    throw new Error('Local wake-word recognition is not available in this browser.');
                }

                const normalizedPhrase = normalizeWakeTranscript(phrase);
                let recognition = null;
                let stopped = true;
                let restartTimer = 0;
                let restartDelay = 250;
                let lastRecognitionStartedAt = 0;
                let wakeTriggered = false;
                let wakeTimer = 0;
                let lastWakeTranscript = '';

                const scheduleRecognitionRestart = (delay = restartDelay) => {
                    if (stopped) return;
                    window.clearTimeout(restartTimer);
                    restartTimer = window.setTimeout(startRecognition, delay);
                    restartDelay = Math.min(2000, Math.round(Math.max(restartDelay, delay) * 1.6));
                };

                const fireWake = () => {
                    if (wakeTriggered) return;
                    wakeTriggered = true;
                    window.clearTimeout(wakeTimer);
                    const tail = extractBeanWakeTail(lastWakeTranscript, phrase);
                    onWake?.({ source: 'local-speech-recognition', transcript: lastWakeTranscript, tail });
                };

                const startRecognition = () => {
                    if (stopped) return;
                    window.clearTimeout(restartTimer);
                    recognition = new Recognition();
                    lastRecognitionStartedAt = Date.now();
                    recognition.lang = language;
                    recognition.continuous = true;
                    recognition.interimResults = true;
                    recognition.processLocally = true;
                    recognition.onresult = (event) => {
                        const transcript = Array.from(event.results || [])
                            .slice(event.resultIndex || 0)
                            .map((result) => result?.[0]?.transcript || '')
                            .join(' ');
                        lastWakeTranscript = transcript;
                        restartDelay = 250;
                        if (!normalizeWakeTranscript(transcript).includes(normalizedPhrase)) return;
                        const tail = extractBeanWakeTail(transcript, phrase);
                        if (tail) {
                            window.clearTimeout(wakeTimer);
                            const isFinal = Array.from(event.results || [])
                                .slice(event.resultIndex || 0)
                                .some((result) => result?.isFinal);
                            const delay = beanWakeTailSubmitDelay(tail, isFinal);
                            wakeTimer = window.setTimeout(fireWake, delay);
                        } else if (!wakeTimer) {
                            wakeTimer = window.setTimeout(fireWake, 700);
                        }
                    };
                    recognition.onerror = (event) => {
                        if (event?.error === 'not-allowed' || event?.error === 'service-not-allowed') {
                            state.bean.error = 'Microphone access is required for local “Hey Bean” wake listening.';
                            state.bean.statusText = 'On — microphone permission needed';
                            render();
                        }
                    };
                    recognition.onend = () => {
                        recognition = null;
                        if (stopped) return;
                        if (Date.now() - lastRecognitionStartedAt > 5000) restartDelay = 250;
                        scheduleRecognitionRestart();
                    };
                    try {
                        recognition.start();
                    } catch (_) {
                        recognition = null;
                        scheduleRecognitionRestart(500);
                    }
                };

                return {
                    async start() {
                        stopped = false;
                        startRecognition();
                    },
                    stop() {
                        stopped = true;
                        window.clearTimeout(restartTimer);
                        window.clearTimeout(wakeTimer);
                        recognition?.stop?.();
                        recognition = null;
                    },
                };
            },
        };
    }

    function normalizeWakeTranscript(value) {
        return String(value || '').toLowerCase().replace(/[^a-z0-9]+/g, ' ').replace(/\s+/g, ' ').trim();
    }

    function extractBeanWakeTail(transcript, phrase = 'Hey Bean') {
        const raw = String(transcript || '').trim();
        if (!raw) return '';
        const normalizedPhrase = normalizeWakeTranscript(phrase);
        const words = raw.split(/\s+/);
        for (let index = 0; index < words.length; index += 1) {
            const candidate = normalizeWakeTranscript(words.slice(index).join(' '));
            if (!candidate.startsWith(normalizedPhrase)) continue;
            return words.slice(index + normalizedPhrase.split(' ').length).join(' ').trim();
        }
        return '';
    }

    function isLikelyCompleteBeanWakeTail(value) {
        const normalized = String(value || '').toLowerCase().replace(/[^\p{L}\p{N}]+/gu, ' ').replace(/\s+/g, ' ').trim();
        if (!normalized) return false;
        const words = normalized.split(/\s+/).filter(Boolean);
        if (words.length < 3) return false;
        if (/\b(what|what s|what is|which|where|when|who|how|can|could|would|will|please|show|list|create|add|complete|delete|remind|schedule|find|search)\b/u.test(normalized)) {
            return words.length >= 4 || /\b(today|tomorrow|weather|calendar|task|tasks|todo|reminder|note|workspace|recipe|card|date|time)\b/u.test(normalized);
        }
        return words.length >= 5;
    }

    function weatherIntentHasEnoughLocationContext(value) {
        const normalized = String(value || '').toLowerCase().replace(/[^\p{L}\p{N}]+/gu, ' ').replace(/\s+/g, ' ').trim();
        if (!isWeatherIntent(normalized)) return true;
        return /\b(here|outside|near me|my location|current location|in|for|near|around|at)\b/u.test(normalized);
    }

    function beanWakeTailSubmitDelay(tail, isFinal = false) {
        if (isFinal) return 250;
        if (isWeatherIntent(tail) && !weatherIntentHasEnoughLocationContext(tail)) return 1300;
        return isLikelyCompleteBeanWakeTail(tail) ? 900 : 1300;
    }

    function clearBeanPendingWakeTail() {
        window.clearTimeout(beanPendingWakeTailTimer);
        beanPendingWakeTailTimer = 0;
        beanPendingWakeTail = '';
    }

    function clearBeanPendingVoiceResponse() {
        window.clearTimeout(beanPendingVoiceResponseTimer);
        beanPendingVoiceResponseTimer = 0;
        beanPendingVoiceResponse = null;
    }

    function watchPendingBeanVoiceResponse(turnId, content) {
        clearBeanPendingVoiceResponse();
        beanPendingVoiceResponse = {
            turnId,
            content: String(content || ''),
            startedAt: Date.now(),
            resolved: false,
        };
        beanPendingVoiceResponseTimer = window.setTimeout(() => {
            if (!beanPendingVoiceResponse || beanPendingVoiceResponse.turnId !== turnId || beanPendingVoiceResponse.resolved) return;
            logBeanVoiceLifecycleEvent('voice_request_timed_out', { label: beanPendingVoiceResponse.content.slice(0, 160) });
            clearBeanPendingVoiceResponse();
            state.bean.busy = false;
            state.bean.error = 'Bean took too long to answer by voice.';
            logBeanVoiceLifecycleEvent('voice_request_recovered_to_wake', { reason: 'request_timeout', transport: 'elevenlabs_agent' });
            stopBeanVoiceSession({ statusText: 'Voice reset — listening for “Hey Bean”' });
            render();
        }, 60000);
    }

    function setBeanVoiceInputEnabled(enabled) {
        beanElevenLabsConversation?.setMicMuted?.(!enabled);
        beanMediaStream?.getAudioTracks?.().forEach((track) => {
            track.enabled = Boolean(enabled);
        });
    }

    function clearBeanVoiceIdleTimer() {
        if (!beanVoiceIdleTimer) return;
        window.clearTimeout(beanVoiceIdleTimer);
        beanVoiceIdleTimer = 0;
    }

    function markBeanVoiceActivity() {
        beanLastVoiceActivityAt = Date.now();
    }

    function scheduleBeanVoiceIdleClose(reason = 'idle_timeout') {
        clearBeanVoiceIdleTimer();
        if (!state.bean.voiceActive || state.bean.busy || beanPendingVoiceResponse) return;
        const startedAt = Date.now();
        if (!beanLastVoiceActivityAt) beanLastVoiceActivityAt = startedAt;
        const idleCloseMs = beanVoiceRequestCount > 0 ? beanVoiceFollowUpIdleCloseMs : beanVoiceInitialIdleCloseMs;
        beanVoiceIdleTimer = window.setTimeout(() => {
            beanVoiceIdleTimer = 0;
            if (!state.bean.voiceActive || state.bean.busy || beanPendingVoiceResponse) return;
            const elapsedSinceActivity = Date.now() - Math.max(beanLastVoiceActivityAt, startedAt);
            if (elapsedSinceActivity < idleCloseMs) {
                scheduleBeanVoiceIdleClose(`${reason}_after_activity`);
                return;
            }
            logBeanVoiceLifecycleEvent('voice_idle_timeout_closed', { reason, transport: 'elevenlabs_agent', idle_close_ms: idleCloseMs });
            endBeanVoiceConversationForWake('Listening locally for “Hey Bean”');
            render();
        }, idleCloseMs);
    }

    function isBeanNonSpeechTranscript(value) {
        const raw = String(value || '').trim();
        if (!raw) return true;
        const normalized = raw.toLowerCase().replace(/[\s\p{P}\p{S}]+/gu, ' ').trim();
        if (!normalized) return true;
        return ['ellipsis', 'silence', 'typing', 'keyboard clacking', 'background noise', 'noise'].includes(normalized);
    }

    function endBeanVoiceConversationForWake(statusText = 'Listening locally for “Hey Bean”') {
        const dismissed = /dismiss/i.test(statusText);
        stopBeanVoiceSession({ statusText });
        if (dismissed) logBeanVoiceLifecycleEvent('dismiss_closed', { label: statusText });
        state.bean.mode = localStorage.getItem('heybean.bean.privacy') === 'listening' ? 'wake_listening' : 'privacy';
        state.bean.statusText = state.bean.mode === 'privacy' ? 'Privacy mode' : statusText;
    }

    function stopBeanWakeListening() {
        beanWakeListeningStarting = false;
        beanWakeDetector?.stop?.();
        beanWakeDetector = null;
    }

    function handleBeanWakeDetected(event = {}) {
        if (state.bean.mode === 'privacy' || state.bean.voiceActive || state.bean.voiceConnecting) return;
        const wakeTail = String(event?.tail || extractBeanWakeTail(event?.transcript || event?.text || event?.utterance || '') || '').trim();
        state.bean.panelOpen = true;
        state.bean.statusText = 'Hey Bean heard — keep talking…';
        if (wakeTail) {
            state.bean.voiceTranscript = wakeTail;
            beanPendingWakeTail = wakeTail;
        }
        beanVoiceClientSessionId = newBeanVoiceEventId('voice-session');
        beanVoiceClientTurnId = '';
        logBeanVoiceLifecycleEvent('wake_detected', { label: wakeTail, has_wake_tail: Boolean(wakeTail), source: event?.source || 'wake_detector' });
        render();
        const voiceStart = startBeanVoiceSession({ wakeEvent: event, wakeTail });
        voiceStart?.catch?.(() => {});
    }

    async function toggleBeanVoiceSession() {
        if (state.bean.voiceActive || state.bean.voiceConnecting) {
            stopBeanVoiceSession();
            render();
            return;
        }
        await startBeanVoiceSession();
    }

    function prewarmBeanRealtimeSession() {
        fetchBeanRealtimeSession().catch(() => {
            beanRealtimeSessionCache = null;
            beanRealtimeSessionPromise = null;
        });
    }

    async function fetchBeanRealtimeSession() {
        if (beanRealtimeSessionUsable(beanRealtimeSessionCache)) return beanRealtimeSessionCache;
        if (beanRealtimeSessionPromise) return beanRealtimeSessionPromise;
        beanRealtimeSessionPromise = (async () => api('/bean/elevenlabs/conversation-token', {
            method: 'POST',
            body: {
                session_id: state.bean.sessionId || null,
                ...clientTimezonePayload(),
                ...await clientLocationPrehydrationPayload(),
            },
        }))()
            .then((session) => {
                beanRealtimeSessionCache = session;
                return session;
            })
            .finally(() => {
                beanRealtimeSessionPromise = null;
            });
        return beanRealtimeSessionPromise;
    }

    function beanRealtimeSessionUsable(session) {
        if (!session) return false;
        return Boolean(session.token && session.agent_id && session.transport === 'elevenlabs_agent');
    }

    async function askBeanFromElevenLabsAgent(parameters = {}) {
        const content = String(parameters.message || parameters.content || parameters.request || '').trim();
        if (!content) return 'I did not receive a Bean request.';

        markBeanVoiceActivity();
        const turnId = newBeanVoiceEventId('voice-agent-tool');
        beanVoiceClientTurnId = turnId;
        beanVoiceRequestCount += 1;
        logBeanVoiceLifecycleEvent('bean_request_sent', { label: content.slice(0, 160), transport: 'elevenlabs_agent', tool: 'askBean' });
        clearBeanVoiceIdleTimer();
        watchPendingBeanVoiceResponse(turnId, content);
        state.bean.busy = true;
        state.bean.mode = 'working';
        state.bean.statusText = 'Working…';
        render();

        const requestPromise = (async () => api('/bean/messages', {
            method: 'POST',
            body: {
                session_id: state.bean.sessionId || null,
                content,
                source: 'elevenlabs_agent',
                ...clientTimezonePayload(),
                ...await clientLocationPayload(content),
            },
        }))();

        try {
            const winner = await Promise.race([
                requestPromise.then((data) => ({ type: 'completed', data })),
                new Promise((resolve) => window.setTimeout(() => resolve({ type: 'background_handoff' }), beanVoiceBackgroundHandoffMs)),
            ]);

            if (winner.type === 'completed') {
                return applyBeanVoiceToolResult(winner.data, content, 'foreground');
            }

            beanVoiceBackgroundHandoff = {
                turnId,
                content,
                startedAt: Date.now(),
                awaitingClose: true,
            };
            state.bean.statusText = 'Finishing in background…';
            logBeanVoiceLifecycleEvent('voice_background_handoff_started', {
                label: content.slice(0, 160),
                transport: 'elevenlabs_agent',
                tool: 'askBean',
                handoff_ms: beanVoiceBackgroundHandoffMs,
            });
            requestPromise
                .then((data) => handleBeanBackgroundToolResult(data, content, turnId))
                .catch((error) => handleBeanBackgroundToolError(error, content, turnId));
            render();
            return beanVoiceBackgroundHandoffMessage;
        } catch (error) {
            logBeanVoiceRequestError(error, content);
            clearBeanPendingVoiceResponse();
            state.bean.busy = false;
            return 'I hit a problem checking Bean. Please try that again.';
        }
    }

    function applyBeanVoiceToolResult(data, content, deliveryMode = 'foreground') {
        state.bean.sessionId = data.session?.id || state.bean.sessionId;
        state.bean.messages = normalizeList(data.messages);
        state.bean.activity = normalizeList(data.activity);
        state.bean.confirmations = normalizeList(data.confirmations);
        scheduleDashboardLiveRefresh([], { immediate: true, forceRender: true });
        const answer = latestBeanAssistantMessage() || String(data.run?.output || '').trim() || 'Bean finished that.';
        markBeanVoiceActivity();
        logBeanVoiceLifecycleEvent('bean_response_received', {
            run_id: data.run?.id || null,
            failed: data.run?.status === 'failed',
            label: answer.slice(0, 160),
            transport: 'elevenlabs_agent',
            tool: 'askBean',
            delivery_mode: deliveryMode,
        });
        clearBeanPendingVoiceResponse();
        state.bean.busy = false;
        render();
        return answer;
    }

    function logBeanVoiceRequestError(error, content, extra = {}) {
        logBeanVoiceLifecycleEvent('voice_request_error', {
            label: content.slice(0, 160),
            error_name: String(error?.name || '').slice(0, 80),
            error_status: error?.status || null,
            error_message: String(error?.message || '').slice(0, 160),
            transport: 'elevenlabs_agent',
            tool: 'askBean',
            ...extra,
        });
    }

    function handleBeanBackgroundToolResult(data, content, turnId) {
        const answer = applyBeanVoiceToolResult(data, content, 'background');
        const runId = data.run?.id || null;
        logBeanVoiceLifecycleEvent('voice_background_result_ready', {
            run_id: runId,
            label: answer.slice(0, 160),
            transport: 'elevenlabs_agent',
            original_turn_id: turnId,
        });
        deliverBeanBackgroundVoiceResult(answer, content, runId);
    }

    function handleBeanBackgroundToolError(error, content, turnId) {
        logBeanVoiceRequestError(error, content, { delivery_mode: 'background', original_turn_id: turnId });
        clearBeanPendingVoiceResponse();
        state.bean.busy = false;
        render();
        deliverBeanBackgroundVoiceResult('I finished checking, but I hit a problem completing that request. Please try again.', content, null);
    }

    function isBeanBackgroundHandoffMessage(content) {
        return /finish it in the background|come back when it/i.test(String(content || ''));
    }

    function clearBeanVoiceBackgroundHandoffCloseTimer() {
        if (beanVoiceBackgroundHandoffCloseTimer) window.clearTimeout(beanVoiceBackgroundHandoffCloseTimer);
        beanVoiceBackgroundHandoffCloseTimer = 0;
    }

    function scheduleBeanVoiceBackgroundHandoffClose(reason = 'handoff_spoken') {
        if (!beanVoiceBackgroundHandoff?.awaitingClose) return;
        clearBeanVoiceBackgroundHandoffCloseTimer();
        const spokenAt = beanVoiceBackgroundHandoff.spokenAt || Date.now();
        const delay = Math.max(0, beanVoiceBackgroundHandoffMinSpeakMs - (Date.now() - spokenAt));
        beanVoiceBackgroundHandoffCloseTimer = window.setTimeout(() => {
            beanVoiceBackgroundHandoffCloseTimer = 0;
            closeBeanVoiceForBackgroundWork(reason);
        }, delay);
    }

    function closeBeanVoiceForBackgroundWork(reason = 'handoff_spoken') {
        if (!beanVoiceBackgroundHandoff) return;
        clearBeanVoiceBackgroundHandoffCloseTimer();
        logBeanVoiceLifecycleEvent('voice_background_handoff_closed', {
            reason,
            label: beanVoiceBackgroundHandoff.content.slice(0, 160),
            transport: 'elevenlabs_agent',
        });
        stopBeanVoiceSession({ keepStatus: true });
        state.bean.busy = true;
        state.bean.mode = 'working';
        state.bean.statusText = 'Finishing in background…';
        render();
    }

    function deliverBeanBackgroundVoiceResult(answer, originalRequest, runId = null) {
        const content = String(answer || '').trim() || 'Bean finished that.';
        beanVoiceBackgroundHandoff = null;
        beanRealtimeSessionCache = null;
        beanRealtimeSessionPromise = null;
        if (state.bean.voiceActive || state.bean.voiceConnecting) {
            stopBeanVoiceSession({ keepStatus: true });
        }
        state.bean.panelOpen = true;
        state.bean.busy = false;
        state.bean.mode = 'speaking';
        state.bean.statusText = 'Bean is back…';
        logBeanVoiceLifecycleEvent('voice_background_result_starting', {
            run_id: runId,
            label: content.slice(0, 160),
            transport: 'elevenlabs_agent',
        });
        render();
        startBeanVoiceSession({
            backgroundResultMessage: content,
            backgroundOriginalRequest: originalRequest,
            backgroundRunId: runId,
        })?.catch?.((error) => {
            logBeanVoiceLifecycleEvent('voice_background_result_start_failed', {
                run_id: runId,
                label: String(error?.message || error).slice(0, 160),
                transport: 'elevenlabs_agent',
            });
        });
    }

    function markBeanAudioPlaybackBlocked(reason, context = {}) {
        beanAudioPlaybackBlocked = true;
        state.bean.error = 'Voice audio is blocked by the browser. Tap the Bean panel once to enable audio.';
        state.bean.statusText = 'Tap Bean to enable voice audio';
        logBeanVoiceLifecycleEvent('audio_playback_blocked', { reason, transport: 'elevenlabs_agent', ...context });
        render();
    }

    function beanElevenLabsRoom(conversation = beanElevenLabsConversation) {
        return conversation?.connection?.getRoom?.() || conversation?.connection?.room || null;
    }

    function beanElevenLabsAudioAdapter(conversation = beanElevenLabsConversation) {
        return conversation?.connection?.audioAdapter || null;
    }

    async function resumeBeanElevenLabsAudioPlayback(reason = 'resume') {
        const conversation = beanElevenLabsConversation;
        if (!conversation) return false;
        const room = beanElevenLabsRoom(conversation);
        const adapter = beanElevenLabsAudioAdapter(conversation);
        let resumed = false;
        try {
            await adapter?.inputAudioContext?.resume?.();
            await adapter?.audioCaptureContext?.resume?.();
            if (room?.startAudio) {
                await room.startAudio();
                resumed = true;
            }
            const elements = Array.from(adapter?.audioElements || []);
            for (const element of elements) {
                element.muted = false;
                element.volume = 1;
                try {
                    await element.play?.();
                    resumed = true;
                } catch (error) {
                    markBeanAudioPlaybackBlocked(reason, {
                        element_paused: Boolean(element.paused),
                        error_message: String(error?.message || error).slice(0, 160),
                    });
                    return false;
                }
            }
            if (resumed) {
                beanAudioPlaybackBlocked = false;
                if (state.bean.error && /voice audio is blocked/i.test(state.bean.error)) state.bean.error = '';
                logBeanVoiceLifecycleEvent('audio_playback_resumed', {
                    reason,
                    transport: 'elevenlabs_agent',
                    audio_elements: elements.length,
                    capture_context_state: adapter?.audioCaptureContext?.state || '',
                });
                return true;
            }
            return false;
        } catch (error) {
            markBeanAudioPlaybackBlocked(reason, { error_message: String(error?.message || error).slice(0, 160) });
            return false;
        }
    }

    function probeBeanElevenLabsOutputVolume(reason = 'probe') {
        const now = Date.now();
        const outputVolume = Number(beanElevenLabsConversation?.getOutputVolume?.() || 0);
        if (outputVolume > 0.01 && now - beanLastOutputVolumeAt > 750) {
            beanLastOutputVolumeAt = now;
            beanAudioPlaybackBlocked = false;
            logBeanVoiceLifecycleEvent('audio_output_detected', {
                reason,
                transport: 'elevenlabs_agent',
                output_volume: Number(outputVolume.toFixed(4)),
            });
        }
    }

    function startBeanOutputVolumeProbe() {
        if (beanOutputVolumeProbeTimer) return;
        beanOutputVolumeProbeTimer = window.setInterval(() => probeBeanElevenLabsOutputVolume('interval'), 500);
    }

    function stopBeanOutputVolumeProbe() {
        if (!beanOutputVolumeProbeTimer) return;
        window.clearInterval(beanOutputVolumeProbeTimer);
        beanOutputVolumeProbeTimer = 0;
    }

    function setupBeanLiveKitAudioDiagnostics(conversation) {
        beanLiveKitDiagnosticsCleanup?.();
        beanLiveKitDiagnosticsCleanup = null;
        const room = beanElevenLabsRoom(conversation);
        if (!room?.on) return;

        const summarizeTrack = (track, publication, participant) => ({
            transport: 'elevenlabs_agent',
            track_kind: String(track?.kind || ''),
            track_sid: String(publication?.trackSid || publication?.sid || track?.sid || '').slice(0, 80),
            participant_identity: String(participant?.identity || '').slice(0, 80),
            media_ready_state: String(track?.mediaStreamTrack?.readyState || ''),
            media_muted: Boolean(track?.mediaStreamTrack?.muted),
            audio_elements: Array.from(beanElevenLabsAudioAdapter(conversation)?.audioElements || []).length,
        });
        const onTrackSubscribed = (track, publication, participant) => {
            logBeanVoiceLifecycleEvent('agent_audio_track_subscribed', summarizeTrack(track, publication, participant));
            resumeBeanElevenLabsAudioPlayback('track_subscribed');
            startBeanOutputVolumeProbe();
        };
        const onTrackSubscriptionFailed = (trackSid, participant) => {
            logBeanVoiceLifecycleEvent('agent_audio_track_failed', {
                transport: 'elevenlabs_agent',
                track_sid: String(trackSid || '').slice(0, 80),
                participant_identity: String(participant?.identity || '').slice(0, 80),
            });
        };
        const onConnectionStateChanged = (connection_state) => {
            logBeanVoiceLifecycleEvent('voice_livekit_state', {
                transport: 'elevenlabs_agent',
                connection_state: String(connection_state || '').slice(0, 80),
            });
        };

        room.on('trackSubscribed', onTrackSubscribed);
        room.on('trackSubscriptionFailed', onTrackSubscriptionFailed);
        room.on('connectionStateChanged', onConnectionStateChanged);
        window.setTimeout(() => {
            const participants = Array.from(room.remoteParticipants?.values?.() || []);
            logBeanVoiceLifecycleEvent('voice_livekit_participants', {
                transport: 'elevenlabs_agent',
                participant_count: participants.length,
                identities: participants.map((participant) => String(participant?.identity || '').slice(0, 40)).join(','),
            });
            for (const participant of participants) {
                const publications = Array.from(participant?.trackPublications?.values?.() || participant?.audioTrackPublications?.values?.() || []);
                for (const publication of publications) {
                    const track = publication?.track;
                    if (track) onTrackSubscribed(track, publication, participant);
                }
            }
        }, 0);
        beanLiveKitDiagnosticsCleanup = () => {
            room.off?.('trackSubscribed', onTrackSubscribed);
            room.off?.('trackSubscriptionFailed', onTrackSubscriptionFailed);
            room.off?.('connectionStateChanged', onConnectionStateChanged);
        };
    }

    function handleBeanElevenLabsDebug(info = {}) {
        const type = String(info?.type || 'debug');
        if (type === 'audio_element_ready') {
            logBeanVoiceLifecycleEvent('audio_element_ready', { transport: 'elevenlabs_agent' });
            resumeBeanElevenLabsAudioPlayback('audio_element_ready');
            return;
        }
        if (/audio|playback|connection|track|error/i.test(type)) {
            logBeanVoiceLifecycleEvent('voice_sdk_debug', {
                label: type,
                transport: 'elevenlabs_agent',
                context: JSON.stringify(info).slice(0, 240),
            });
        }
    }

    function handleBeanElevenLabsAudio(base64Audio) {
        const now = Date.now();
        markBeanVoiceActivity();
        if (now - beanLastAudioChunkAt > 750) {
            beanLastAudioChunkAt = now;
            logBeanVoiceLifecycleEvent('audio_chunk_received', {
                transport: 'elevenlabs_agent',
                bytes_base64: String(base64Audio || '').length,
            });
        }
        probeBeanElevenLabsOutputVolume('audio_chunk');
    }

    function submitCompleteBeanWakeTail(wakeTail) {
        const content = String(wakeTail || '').trim();
        if (!content || !beanElevenLabsConversation || !state.bean.voiceActive) return false;
        if (beanSubmittedWakeTail === content) return true;
        beanSubmittedWakeTail = content;
        beanVoiceClientTurnId = newBeanVoiceEventId('voice-turn');
        state.bean.voiceTranscript = content;
        clearBeanVoiceIdleTimer();
        logBeanVoiceLifecycleEvent('wake_tail_submitted', { label: content.slice(0, 160), transport: 'elevenlabs_agent' });
        beanElevenLabsConversation?.sendUserMessage?.(content);
        beanElevenLabsConversation?.sendUserActivity?.();
        return true;
    }

    async function startBeanVoiceSession(options = {}) {
        if (!navigator.mediaDevices?.getUserMedia || !Conversation?.startSession) {
            state.bean.error = 'Voice requires a browser with microphone support and the ElevenLabs realtime client.';
            state.bean.mode = 'error';
            state.bean.statusText = 'Voice is unavailable';
            render();
            return;
        }

        stopBeanWakeListening();
        state.bean.panelOpen = true;
        state.bean.voiceConnecting = true;
        state.bean.error = '';
        state.bean.mode = 'listening';
        state.bean.statusText = options?.wakeEvent ? 'Hey Bean heard — keep talking…' : 'Connecting voice…';
        render();

        try {
            const realtime = await fetchBeanRealtimeSession();
            beanRealtimeSessionCache = null;
            if (!realtime?.token || !realtime?.agent_id) throw new Error('ElevenLabs Agent session did not return credentials.');
            state.bean.sessionId = realtime.bean_session_id || state.bean.sessionId;

            const wakeTail = String(options?.wakeTail || '').trim();
            const backgroundResultMessage = String(options?.backgroundResultMessage || '').trim();
            const backgroundDeliveryPrompt = backgroundResultMessage
                ? `BACKGROUND_RESULT_DELIVERY: ${backgroundResultMessage}`
                : '';
            beanAudioPlaybackBlocked = false;
            beanElevenLabsConversation = await Conversation.startSession({
                conversationToken: realtime.token,
                connectionType: 'webrtc',
                textOnly: false,
                useWakeLock: true,
                userId: state.user?.id ? `bean-user-${state.user.id}` : undefined,
                dynamicVariables: {
                    bean_session_id: Number(state.bean.sessionId || realtime.bean_session_id || 0),
                    bean_client_timezone: clientTimezonePayload().client_timezone || '',
                    bean_workspace_id: Number(realtime.dashboard_context?.workspace_id || 0),
                    bean_dashboard_context: JSON.stringify(realtime.dashboard_context || {}),
                    bean_background_original_request: String(options?.backgroundOriginalRequest || ''),
                    bean_background_result: backgroundResultMessage,
                },
                clientTools: {
                    askBean: askBeanFromElevenLabsAgent,
                },
                onConversationCreated: (conversation) => {
                    beanElevenLabsConversation = conversation;
                    setupBeanLiveKitAudioDiagnostics(conversation);
                    conversation?.setVolume?.({ volume: 1 });
                    logBeanVoiceLifecycleEvent('voice_conversation_created', {
                        transport: 'elevenlabs_agent',
                        conversation_type: conversation?.type || '',
                    });
                },
                onConnect: async () => {
                    state.bean.voiceActive = true;
                    state.bean.voiceConnecting = false;
                    markBeanVoiceActivity();
                    beanVoiceRequestCount = 0;
                    if (!beanVoiceClientSessionId) beanVoiceClientSessionId = newBeanVoiceEventId('voice-session');
                    beanVoiceClientTurnId = '';
                    logBeanVoiceLifecycleEvent('voice_session_started', {
                        has_wake_event: Boolean(options?.wakeEvent),
                        has_wake_tail: Boolean(wakeTail),
                        has_first_message: false,
                        has_background_result: Boolean(backgroundDeliveryPrompt),
                        background_run_id: options?.backgroundRunId || null,
                        transport: 'elevenlabs_agent',
                    });
                    if (backgroundDeliveryPrompt) {
                        state.bean.mode = 'speaking';
                        state.bean.statusText = 'Bean is back…';
                    } else if (!state.bean.busy) {
                        state.bean.mode = 'listening';
                        state.bean.statusText = 'Listening — speak to Bean';
                    }
                    render();
                    resumeBeanElevenLabsAudioPlayback('connect');

                    if (backgroundDeliveryPrompt) {
                        window.setTimeout(() => {
                            try {
                                beanElevenLabsConversation?.sendUserMessage?.(backgroundDeliveryPrompt);
                                logBeanVoiceLifecycleEvent('voice_background_result_prompt_sent', {
                                    background_run_id: options?.backgroundRunId || null,
                                    transport: 'elevenlabs_agent',
                                });
                            } catch (error) {
                                logBeanVoiceLifecycleEvent('voice_background_result_prompt_failed', {
                                    background_run_id: options?.backgroundRunId || null,
                                    label: String(error?.message || error).slice(0, 160),
                                    transport: 'elevenlabs_agent',
                                });
                            }
                        }, 250);
                    }

                    if (wakeTail && isLikelyCompleteBeanWakeTail(wakeTail)) {
                        window.setTimeout(() => {
                            submitCompleteBeanWakeTail(wakeTail);
                        }, beanWakeTailSubmitDelay(wakeTail, true));
                    }
                },
                onDisconnect: (details) => {
                    const shouldReset = state.bean.voiceActive || state.bean.voiceConnecting;
                    beanLiveKitDiagnosticsCleanup?.();
                    beanLiveKitDiagnosticsCleanup = null;
                    clearBeanVoiceIdleTimer();
                    stopBeanOutputVolumeProbe();
                    beanElevenLabsConversation = null;
                    state.bean.voiceActive = false;
                    state.bean.voiceConnecting = false;
                    clearBeanPendingVoiceResponse();
                    if (shouldReset) {
                        logBeanVoiceLifecycleEvent('voice_session_closed', { transport: 'elevenlabs_agent', reason: details?.reason || '' });
                    }
                    if (state.bean.mode !== 'privacy') {
                        setBeanIdleStatus();
                        if (state.bean.mode !== 'privacy') startBeanWakeListening();
                    }
                    render();
                },
                onError: (message, context) => {
                    logBeanVoiceLifecycleEvent('voice_session_error', { label: String(message || '').slice(0, 160), transport: 'elevenlabs_agent', context: context ? String(context).slice(0, 160) : '' });
                    state.bean.error = String(message || 'ElevenLabs voice error.');
                    logBeanVoiceLifecycleEvent('voice_request_recovered_to_wake', { reason: 'session_error', transport: 'elevenlabs_agent' });
                    stopBeanVoiceSession({ statusText: 'Voice reset — listening for “Hey Bean”' });
                    render();
                },
                onMessage: (message) => handleBeanElevenLabsMessage(message),
                onAudio: (base64Audio) => handleBeanElevenLabsAudio(base64Audio),
                onDebug: (info) => handleBeanElevenLabsDebug(info),
                onModeChange: ({ mode } = {}) => handleBeanElevenLabsMode(mode),
                onStatusChange: ({ status } = {}) => {
                    if (status === 'connected') {
                        if (!backgroundDeliveryPrompt) state.bean.statusText = 'Listening — speak to Bean';
                        if (wakeTail && isLikelyCompleteBeanWakeTail(wakeTail)) {
                            window.setTimeout(() => submitCompleteBeanWakeTail(wakeTail), beanWakeTailSubmitDelay(wakeTail, true));
                        }
                        render();
                    }
                },
            });
        } catch (error) {
            state.bean.error = friendlyError(error, 'start Bean voice');
            logBeanVoiceLifecycleEvent('voice_request_recovered_to_wake', { reason: 'start_error', transport: 'elevenlabs_agent' });
            stopBeanVoiceSession({ statusText: 'Voice could not start — listening for “Hey Bean”' });
        }
        render();
    }

    function stopBeanVoiceSession(options = {}) {
        const wasActive = state.bean.voiceActive || state.bean.voiceConnecting;
        clearBeanPendingWakeTail();
        clearBeanVoiceBackgroundHandoffCloseTimer();
        beanSubmittedWakeTail = '';
        clearBeanPendingVoiceResponse();
        clearBeanVoiceIdleTimer();
        beanVoiceRequestCount = 0;
        setBeanVoiceInputEnabled(false);
        beanLiveKitDiagnosticsCleanup?.();
        beanLiveKitDiagnosticsCleanup = null;
        stopBeanOutputVolumeProbe();
        beanElevenLabsConversation?.endSession?.().catch(() => {});
        beanElevenLabsConversation = null;
        beanMediaStream?.getTracks?.().forEach((track) => track.stop());
        beanMediaStream = null;
        state.bean.voiceActive = false;
        state.bean.voiceConnecting = false;
        state.bean.voiceTranscript = '';
        if (wasActive) {
            logBeanVoiceLifecycleEvent('voice_session_closed', { keep_status: Boolean(options.keepStatus), label: options.statusText || state.bean.statusText || '' });
        }
        beanVoiceClientSessionId = '';
        beanVoiceClientTurnId = '';
        beanLastAudioChunkAt = 0;
        beanLastOutputVolumeAt = 0;
        beanLastVoiceActivityAt = 0;
        beanAudioPlaybackBlocked = false;
        if (!options.keepStatus) {
            setBeanIdleStatus();
            if (options.statusText && state.bean.mode !== 'privacy') state.bean.statusText = options.statusText;
            if (state.bean.mode !== 'privacy') startBeanWakeListening();
        }
    }

    function handleBeanElevenLabsMessage(message = {}) {
        const role = String(message.role || message.source || '').toLowerCase();
        const content = String(message.message || message.text || '').trim();
        if (!content) return;

        if (role === 'user') {
            markBeanVoiceActivity();
            clearBeanPendingWakeTail();
            clearBeanVoiceIdleTimer();
            if (isBeanNonSpeechTranscript(content)) {
                logBeanVoiceLifecycleEvent('non_speech_transcript_ignored', { label: content.slice(0, 160), transport: 'elevenlabs_agent' });
                scheduleBeanVoiceIdleClose('non_speech_transcript');
                return;
            }
            if (isLikelyBeanAssistantEcho(content)) {
                logBeanVoiceLifecycleEvent('assistant_echo_ignored', { label: content.slice(0, 160), transport: 'elevenlabs_agent' });
                return;
            }
            if (state.bean.mode === 'speaking') {
                logBeanVoiceLifecycleEvent('background_audio_ignored', { label: content.slice(0, 160), reason: 'assistant_speaking', transport: 'elevenlabs_agent' });
                return;
            }
            if (handleBeanVoiceControl(content)) return;
            if (state.bean.busy || Date.now() < beanVoiceInputIgnoreUntil) {
                logBeanVoiceLifecycleEvent('background_audio_ignored', { label: content.slice(0, 160), reason: state.bean.busy ? 'busy' : 'post_speech_cooldown', transport: 'elevenlabs_agent' });
                return;
            }
            const isFollowUpTranscript = beanVoiceRequestCount > 0 && state.bean.mode === 'listening' && state.bean.voiceActive;
            beanVoiceClientTurnId = newBeanVoiceEventId(isFollowUpTranscript ? 'voice-followup' : 'voice-turn');
            logBeanVoiceLifecycleEvent(isFollowUpTranscript ? 'followup_transcript_received' : 'user_transcript_received', { label: content.slice(0, 160), transport: 'elevenlabs_agent' });
            state.bean.voiceTranscript = content;
            state.bean.messages = [...normalizeList(state.bean.messages), { role: 'user', content }];
            beanVoiceRequestCount += 1;
            watchPendingBeanVoiceResponse(beanVoiceClientTurnId, content);
            logBeanVoiceLifecycleEvent('thinking_visible', { label: content.slice(0, 160), transport: 'elevenlabs_agent' });
            logBeanVoiceLifecycleEvent('bean_request_sent', { label: content.slice(0, 160), transport: 'elevenlabs_agent' });
            state.bean.busy = true;
            state.bean.mode = 'thinking';
            state.bean.statusText = 'Thinking…';
            render();
            return;
        }

        if (role === 'agent' || role === 'ai') {
            const pendingTurnId = beanVoiceClientTurnId;
            const isBackgroundHandoff = beanVoiceBackgroundHandoff?.awaitingClose && isBeanBackgroundHandoffMessage(content);
            if (beanPendingVoiceResponse?.turnId === pendingTurnId) {
                beanPendingVoiceResponse.resolved = true;
            }
            clearBeanPendingVoiceResponse();
            markBeanVoiceActivity();
            beanLastSpokenAnswer = content;
            beanLastSpokenAnswerAt = Date.now();
            logBeanVoiceLifecycleEvent('bean_response_received', { label: content.slice(0, 160), transport: 'elevenlabs_agent', agent_managed_response: true, background_handoff: isBackgroundHandoff });
            startBeanOutputVolumeProbe();
            resumeBeanElevenLabsAudioPlayback('agent_message');
            logBeanVoiceLifecycleEvent('assistant_speech_started', { label: content.slice(0, 160), transport: 'elevenlabs_agent' });
            if (isBackgroundHandoff && beanVoiceBackgroundHandoff) {
                beanVoiceBackgroundHandoff.spokenAt = Date.now();
                scheduleBeanVoiceBackgroundHandoffClose('handoff_min_speech_elapsed');
            }
            const latestPersistedAssistant = latestBeanAssistantMessage();
            if (latestPersistedAssistant !== content) {
                state.bean.messages = [...normalizeList(state.bean.messages), { role: 'assistant', content }];
            }
            state.bean.voiceTranscript = '';
            state.bean.busy = isBackgroundHandoff;
            state.bean.mode = 'speaking';
            state.bean.statusText = isBackgroundHandoff ? 'Finishing in background…' : 'Speaking…';
            scheduleDashboardLiveRefresh([], { immediate: true, forceRender: true });
            render();
        }
    }

    function handleBeanElevenLabsMode(mode) {
        const normalizedMode = String(mode || '').toLowerCase();
        if (normalizedMode === 'speaking') {
            markBeanVoiceActivity();
            clearBeanVoiceIdleTimer();
            startBeanOutputVolumeProbe();
            resumeBeanElevenLabsAudioPlayback('mode_speaking');
            if (state.bean.voiceActive && !state.bean.busy) {
                state.bean.mode = 'speaking';
                state.bean.statusText = 'Speaking…';
                render();
            }
            return;
        }
        if (normalizedMode === 'listening') {
            if (state.bean.voiceActive) {
                if (beanVoiceBackgroundHandoff?.awaitingClose) {
                    scheduleBeanVoiceBackgroundHandoffClose('mode_listening_after_handoff');
                    return;
                }
                markBeanVoiceActivity();
                state.bean.mode = 'listening';
                state.bean.statusText = 'Listening — speak to Bean';
                scheduleBeanVoiceIdleClose('mode_listening');
                render();
            }
        }
    }

    function handleBeanVoiceControl(transcript) {
        if (!isBeanStopCommand(transcript)) return false;
        logBeanVoiceLifecycleEvent('dismiss_command_detected', { label: String(transcript || '').slice(0, 160) });
        state.bean.voiceTranscript = '';
        state.bean.error = '';
        endBeanVoiceConversationForWake('Dismissed — listening for “Hey Bean”');
        render();
        return true;
    }

    function isBeanStopCommand(value) {
        const raw = String(value || '').trim();
        const normalized = raw.toLowerCase().replace(/[^\p{L}\p{N}]+/gu, ' ').replace(/\s+/g, ' ').trim();
        if (!normalized) return false;
        const command = normalized.replace(/^(ok|okay|alright|all right)\s+/u, '');
        const englishStops = [
            'stop', 'stop bean', 'hey bean stop', 'stop talking', 'stop listening',
            'cancel', 'cancel that', 'nevermind', 'never mind', 'that is all', 'that s all',
            'thanks', 'thanks bean', 'thank you', 'thank you bean', 'no thanks', 'done', 'all done',
            'dismiss', 'dismiss bean', 'dismissed', 'bye', 'goodbye', 'go away', 'you can stop',
            'that will be all', 'that ll be all', 'we are done', 'we re done',
        ];
        if (englishStops.includes(normalized) || englishStops.includes(command)) return true;
        return /^(停止|停|ストップ|止めて|やめて|終了|キャンセル|取消|别说了|不要说了|结束)$/.test(raw);
    }

    function latestBeanAssistantMessage() {
        return normalizeList(state.bean.messages).slice().reverse().find((message) => String(message.role || '').toLowerCase() === 'assistant')?.content || '';
    }

    function normalizeBeanVoiceText(value) {
        return String(value || '').toLowerCase().replace(/[^\p{L}\p{N}]+/gu, ' ').replace(/\s+/g, ' ').trim();
    }

    function isLikelyBeanAssistantEcho(transcript) {
        if (!beanLastSpokenAnswer || Date.now() - beanLastSpokenAnswerAt > 15000) return false;
        const spoken = normalizeBeanVoiceText(beanLastSpokenAnswer);
        const heard = normalizeBeanVoiceText(transcript);
        if (!spoken || !heard || heard.length < 8) return false;
        return spoken.includes(heard) || heard.includes(spoken.slice(0, Math.min(heard.length, 80)));
    }

    async function ensureBeanSession() {
        if (state.bean.sessionId) return state.bean.sessionId;
        const session = await api('/bean/sessions', { method: 'POST', body: clientTimezonePayload() });
        state.bean.sessionId = session.id;
        return state.bean.sessionId;
    }

    async function loadBeanActivity() {
        try {
            const sessionId = await ensureBeanSession();
            const data = await api(`/bean/sessions/${encodeURIComponent(sessionId)}/activity`);
            state.bean.messages = normalizeList(data.messages);
            state.bean.activity = normalizeList(data.activity);
            state.bean.confirmations = normalizeList(data.confirmations);
            state.bean.error = '';
        } catch (error) {
            state.bean.error = friendlyError(error, 'load Bean activity');
        }
    }

    async function sendBeanMessage(event) {
        event.preventDefault();
        const content = String(state.bean.input || mount.querySelector('[data-bean-input]')?.value || '').trim();
        await sendBeanMessageContent(content);
    }

    async function sendBeanMessageContent(content) {
        content = String(content || '').trim();
        if (!content || state.bean.busy) return;
        state.bean.busy = true;
        state.bean.input = '';
        state.bean.panelOpen = true;
        state.bean.mode = 'thinking';
        state.bean.statusText = 'Thinking…';
        state.bean.messages = [...normalizeList(state.bean.messages), { role: 'user', content }];
        render();
        try {
            const sessionId = await ensureBeanSession();
            logBeanVoiceLifecycleEvent('bean_request_sent', { label: String(content || '').slice(0, 160) });
            beanVoiceRequestCount += 1;
            const data = await api('/bean/messages', { method: 'POST', body: { session_id: sessionId, content, ...clientTimezonePayload(), ...await clientLocationPayload(content) } });
            state.bean.sessionId = data.session?.id || sessionId;
            const runId = data.run?.id || null;
            logBeanVoiceLifecycleEvent('bean_response_received', { run_id: runId, failed: data.run?.status === 'failed', label: String(data.run?.output || '').slice(0, 160) });
            state.bean.messages = normalizeList(data.messages);
            state.bean.activity = normalizeList(data.activity);
            state.bean.confirmations = normalizeList(data.confirmations);
            setBeanIdleStatus();
            scheduleDashboardLiveRefresh([], { immediate: true, forceRender: true });
        } catch (error) {
            state.bean.error = friendlyError(error, 'ask Bean');
            state.bean.mode = 'error';
            state.bean.statusText = 'Bean hit a problem';
        }
        state.bean.busy = false;
        render();
    }

    async function approveBeanConfirmation(id) {
        if (!id || state.bean.busy) return;
        state.bean.busy = true;
        state.bean.mode = 'working';
        state.bean.statusText = 'Working…';
        render();
        try {
            await api(`/bean/confirmations/${encodeURIComponent(id)}/approve`, { method: 'POST', body: {} });
            await loadBeanActivity();
            scheduleDashboardLiveRefresh([], { immediate: true, forceRender: true });
            setBeanIdleStatus();
        } catch (error) {
            state.bean.error = friendlyError(error, 'confirm Bean action');
            state.bean.mode = 'error';
            state.bean.statusText = 'Bean hit a problem';
        }
        state.bean.busy = false;
        render();
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
                ? registerPayload(data)
                : { email: data.email, password: data.password };
            const result = await api(`/auth/${action}`, { method: 'POST', body: payload });
            if (action === 'register') {
                persistToken(result.token, true);
                state.busy = false;
                state.subscriptionCheckoutStatus = '';
                state.user = result.user || null;
                state.subscriptionSummary = null;
                state.selectedPlan = result.selected_plan || data.plan || state.selectedPlan || 'premium';
                state.selectedBillingInterval = normalizedBillingInterval(result.selected_billing_interval || data.billing_interval || state.selectedBillingInterval);
                state.phase = 'subscription';
                state.notice = 'Your account has been created. Check your email to verify. Next, choose your plan.';
                history.pushState({}, '', `/subscribe?plan=${encodeURIComponent(state.selectedPlan)}&billing_interval=${encodeURIComponent(state.selectedBillingInterval)}`);
                render();
                return;
            }
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

    function registerPayload(data) {
        return {
            name: data.name,
            email: data.email,
            password: data.password,
            password_confirmation: data.password_confirmation,
            ...(data.plan ? { plan: data.plan } : {}),
            billing_interval: normalizedBillingInterval(data.billing_interval || state.selectedBillingInterval),
            theme_mode: ['light', 'dark', 'auto'].includes(data.theme_mode) ? data.theme_mode : 'light',
            ...(browserTimezone() ? { timezone: browserTimezone() } : {}),
        };
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
                body: { plan, billing_interval: normalizedBillingInterval(state.selectedBillingInterval), source: 'subscribe' },
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
        bindBeanActions();
        bindDailyStickyNoteActions();
        mount.querySelectorAll('[data-nav]').forEach((button) => button.addEventListener('click', () => {
            state.selected = button.dataset.nav;
            state.error = '';
            state.notice = '';
            history.pushState({}, '', pathForView(state.selected));
            render();
            if (state.selected === 'admin') loadAdminData();
        }));
        mount.querySelector('[data-onboarding-dashboard]')?.addEventListener('click', () => {
            state.selected = 'today';
            state.onboardingJustCompleted = false;
            state.error = '';
            state.notice = '';
            render();
        });
        mount.querySelector('[data-onboarding-tour-next]')?.addEventListener('click', () => {
            activateOnboardingTourStep(state.onboardingTourStep + 1);
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
            stopBeanEventFeed();
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
            clearPlanLimitError();
            history.pushState({}, '', '/app');
            render();
        });
        mount.querySelector('[data-calendar-month]')?.addEventListener('click', () => {
            const today = new Date();
            state.selected = 'today';
            state.selectedDay = dateOnly(today);
            resetCalendarWindow(today);
            state.showMonth = true;
            clearPlanLimitError();
            history.pushState({}, '', '/app');
            render();
        });
        mount.querySelectorAll('[data-select-day]').forEach((button) => button.addEventListener('click', () => {
            const selected = allowedCalendarDate(button.dataset.selectDay);
            if (selected.blocked) {
                showCalendarHistoryLimit();
            } else {
                clearPlanLimitError();
            }
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
        mount.querySelector('[data-refresh-admin]')?.addEventListener('click', () => loadAdminData(true));
        mount.querySelector('[data-admin-plan-limits-form]')?.addEventListener('submit', saveAdminPlanLimits);
        mount.querySelector('[data-admin-coupon-form]')?.addEventListener('submit', createAdminCouponCode);
        mount.querySelectorAll('[data-admin-coupon-delete]').forEach((button) => button.addEventListener('click', () => deleteAdminCouponCode(button.dataset.adminCouponDelete)));
        mount.querySelectorAll('[data-enterprise-limit-form]').forEach((form) => form.addEventListener('submit', saveEnterpriseLimits));
        mount.querySelectorAll('[data-enterprise-limit-delete]').forEach((button) => button.addEventListener('click', () => deleteEnterpriseLimits(button.dataset.enterpriseLimitDelete)));
        mount.querySelectorAll('[data-user-growth-range]').forEach((button) => button.addEventListener('click', () => setAdminUserGrowthRange(button.dataset.userGrowthRange)));
        mount.querySelector('[data-toggle-archived-issues]')?.addEventListener('click', () => { state.adminArchivedIssuesOpen = !state.adminArchivedIssuesOpen; render(); });
        mount.querySelectorAll('[data-issue-status]').forEach((button) => button.addEventListener('click', () => updateIssueReportStatus(button.dataset.issueStatus, button.dataset.status)));
        mount.querySelectorAll('[data-open-create]').forEach((button) => button.addEventListener('click', () => openModal(button.dataset.openCreate)));
        mount.querySelectorAll('[data-create-note]').forEach((button) => button.addEventListener('click', () => openModal('note-create')));
        mount.querySelectorAll('[data-create-note-folder]').forEach((button) => button.addEventListener('click', createNoteFolder));
        mount.querySelector('[data-toggle-note-folder-edit]')?.addEventListener('click', () => {
            state.noteFoldersEditing = !state.noteFoldersEditing;
            state.noteFolderDragId = '';
            render();
        });
        bindNoteFolderDragOrdering();
        mount.querySelectorAll('[data-note-sort]').forEach((button) => button.addEventListener('click', () => {
            state.notesSort = button.dataset.noteSort || 'recent';
            render();
        }));
        mount.querySelectorAll('[data-note-folder]').forEach((button) => button.addEventListener('click', () => {
            state.selectedNoteFolderId = button.dataset.noteFolder || 'all';
            state.notesDetailOpen = false;
            ensureSelectedNote();
            render();
        }));
        mount.querySelector('[data-notes-search]')?.addEventListener('input', (event) => {
            state.notesSearch = event.currentTarget.value;
            ensureSelectedNote();
            render();
        });
        mount.querySelectorAll('[data-select-note]').forEach((button) => button.addEventListener('click', () => {
            state.selectedNoteId = button.dataset.selectNote || '';
            state.notesDetailOpen = true;
            render();
        }));
        mount.querySelector('[data-note-back]')?.addEventListener('click', () => {
            flushNoteAutosave(mount.querySelector('[data-note-editor]'));
            state.notesDetailOpen = false;
            render();
        });
        mount.querySelectorAll('[data-toggle-note-pin]').forEach((button) => button.addEventListener('click', () => toggleNotePin(button.dataset.toggleNotePin)));
        mount.querySelectorAll('[data-toggle-note-lock]').forEach((button) => button.addEventListener('click', () => toggleNoteLock(button.dataset.toggleNoteLock)));
        mount.querySelectorAll('[data-delete-note]').forEach((button) => button.addEventListener('click', () => deleteNote(button.dataset.deleteNote)));
        mount.querySelectorAll('[data-delete-note-folder]').forEach((button) => button.addEventListener('click', () => deleteNoteFolder(button.dataset.deleteNoteFolder)));
        mount.querySelectorAll('[data-move-note-folder]').forEach((button) => button.addEventListener('click', () => moveNoteFolder(button.dataset.moveNoteFolder)));
        mount.querySelectorAll('[data-note-sync-workspace]').forEach((input) => input.addEventListener('change', () => updateNoteWorkspaceSync(input.closest('[data-note-editor]'))));
        bindNoteEditorAutosave();
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
        mount.querySelectorAll('[data-theme-mode-option]').forEach((input) => input.addEventListener('change', updateThemeModePreference));
        mount.querySelector('[data-billing-interval-select]')?.addEventListener('change', (event) => {
            state.billingPlanInterval = normalizedBillingInterval(event.currentTarget.value);
            render();
        });
        mount.querySelector('[data-billing-change-plan]')?.addEventListener('click', changeBillingPlan);
        mount.querySelector('[data-billing-coupon-code]')?.addEventListener('input', (event) => {
            state.billingCouponCode = event.currentTarget.value.replace(/\D/g, '').slice(0, 6);
        });
        mount.querySelector('[data-billing-coupon-code]')?.addEventListener('keydown', (event) => {
            if (event.key === 'Enter') {
                event.preventDefault();
                redeemCouponCodeFromInput('billing');
            }
        });
        mount.querySelector('[data-billing-apply-coupon]')?.addEventListener('click', () => redeemCouponCodeFromInput('billing'));
        mount.querySelector('[data-billing-update-payment]')?.addEventListener('click', startBillingPaymentUpdate);
        mount.querySelector('[data-billing-refresh]')?.addEventListener('click', () => refreshBillingSettings({ user: true }));
        mount.querySelector('[data-billing-cancel-renewal]')?.addEventListener('click', cancelBillingRenewal);
        mount.querySelector('[data-billing-resume-subscription]')?.addEventListener('click', resumeBillingSubscription);
        mount.querySelectorAll('[data-google-action]').forEach((button) => button.addEventListener('click', () => googleAction(button.dataset.googleAction)));
        mount.querySelector('[data-external-calendar-connect]')?.addEventListener('click', () => { state.modal = { type: 'external-calendar-connect' }; render(); });
        mount.querySelector('[data-external-calendar-import-open]')?.addEventListener('click', () => { state.modal = { type: 'external-calendar-import', providerKey: 'apple' }; render(); });
        mount.querySelectorAll('[data-external-calendar-provider]').forEach((button) => button.addEventListener('click', () => connectExternalCalendar(button.dataset.externalCalendarProvider)));
        mount.querySelectorAll('[data-external-calendar-action]').forEach((button) => button.addEventListener('click', () => externalCalendarAction(button.dataset.externalCalendarAction)));
        mount.querySelectorAll('[data-google-calendar]').forEach((input) => input.addEventListener('change', updateGoogleCalendarSelection));
        mount.querySelectorAll('[data-outlook-calendar]').forEach((input) => input.addEventListener('change', updateOutlookCalendarSelection));
        mount.querySelectorAll('[data-calendar-pref]').forEach((input) => input.addEventListener('change', () => localStorage.setItem(`heybean.calendar.${input.dataset.calendarPref}`, input.value)));
        mount.querySelectorAll('[data-category-select]').forEach((select) => select.addEventListener('change', syncSelectedCategoryColor));
        bindTimelineHorizontalScroll();
        scrollTimelineToSelected();
    }

    function openModal(type, itemOrOptions = null) {
        state.modal = itemOrOptions && type === 'workspace'
            ? { type, mode: itemOrOptions.mode, workspace: itemOrOptions.workspace }
            : itemOrOptions && type === 'task' && itemOrOptions.parentTask
                ? { type, item: null, parentTask: itemOrOptions.parentTask }
            : { type, item: itemOrOptions };
        render();
    }

    async function createNote(form) {
        state.error = '';
        try {
            const workspaceId = selectedPrimaryWorkspaceId(form);
            const syncTo = selectedSyncWorkspaceIds(form, workspaceId);
            const selectedFolderId = /^\d+$/.test(String(state.selectedNoteFolderId || ''))
                && String(workspaceId || '') === String(currentWorkspaceId() || '')
                ? Number(state.selectedNoteFolderId)
                : null;
            const body = {
                title: String(form?.elements?.title?.value || 'New Note').trim() || 'New Note',
                body_markdown: '',
                note_folder_id: selectedFolderId,
                workspace_id: workspaceId ? Number(workspaceId) : null,
                sync_to_workspace_ids: syncTo,
            };
            const note = await api('/notes', { method: 'POST', body });
            state.notes = normalizeNotes(upsertById(state.notes, note));
            state.selectedNoteId = String(note.id);
            state.notesDetailOpen = true;
            state.selected = 'notes';
            state.modal = null;
            saveDashboardCache();
            render();
        } catch (error) {
            state.error = friendlyError(error, 'create that note');
            render();
        }
    }

    function bindNoteFolderDragOrdering() {
        if (!state.noteFoldersEditing) return;
        mount.querySelectorAll('[data-note-folder-row]').forEach((row) => {
            row.addEventListener('dragstart', (event) => {
                const id = row.dataset.noteFolderRow || '';
                state.noteFolderDragId = id;
                row.classList.add('hb-note-folder-edit-row-dragging');
                event.dataTransfer.effectAllowed = 'move';
                event.dataTransfer.setData('text/plain', id);
            });
            row.addEventListener('dragend', () => {
                state.noteFolderDragId = '';
                row.classList.remove('hb-note-folder-edit-row-dragging');
            });
            row.addEventListener('dragover', (event) => {
                event.preventDefault();
                event.dataTransfer.dropEffect = 'move';
                row.classList.add('hb-note-folder-edit-row-drop-target');
            });
            row.addEventListener('dragleave', () => {
                row.classList.remove('hb-note-folder-edit-row-drop-target');
            });
            row.addEventListener('drop', (event) => {
                event.preventDefault();
                row.classList.remove('hb-note-folder-edit-row-drop-target');
                const draggedId = event.dataTransfer.getData('text/plain') || state.noteFolderDragId;
                reorderNoteFolder(draggedId, row.dataset.noteFolderRow || '');
            });
        });
    }

    function reorderNoteFolder(draggedId, targetId) {
        if (!draggedId || !targetId || String(draggedId) === String(targetId)) return;
        const previous = normalizeNoteFolders(state.noteFolders);
        const from = previous.findIndex((folder) => String(folder.id) === String(draggedId));
        const to = previous.findIndex((folder) => String(folder.id) === String(targetId));
        if (from < 0 || to < 0) return;
        const next = [...previous];
        const [moved] = next.splice(from, 1);
        next.splice(to, 0, moved);
        const ordered = next.map((folder, index) => ({
            ...folder,
            sort_order: index,
            sortOrder: index,
        }));
        state.noteFolders = ordered;
        saveDashboardCache();
        render();
        persistNoteFolderOrder(ordered, previous);
    }

    async function persistNoteFolderOrder(folders, previous) {
        try {
            await Promise.all(folders.map((folder, index) => api(`/note-folders/${encodeURIComponent(folder.id)}`, {
                method: 'PATCH',
                body: { sort_order: index },
            })));
            state.noteFolders = normalizeNoteFolders(folders);
            saveDashboardCache();
        } catch (error) {
            state.noteFolders = previous;
            state.error = friendlyError(error, 'save that folder order');
            saveDashboardCache();
            render();
        }
    }

    async function createNoteFolder() {
        const name = window.prompt('Folder name');
        if (!name || !name.trim()) return;
        const normalizedName = name.trim();
        const nameKey = normalizedName.toLocaleLowerCase();
        const existing = state.noteFolders.find((folder) => String(folder.name || '').trim().toLocaleLowerCase() === nameKey);
        if (existing) {
            state.selectedNoteFolderId = String(existing.id);
            render();
            return;
        }
        if (pendingNoteFolderNames.has(nameKey)) return;
        pendingNoteFolderNames.add(nameKey);
        try {
            const folder = await api(workspaceScopedPath('/note-folders'), { method: 'POST', body: { name: normalizedName } });
            state.noteFolders = normalizeNoteFolders([...state.noteFolders, folder]);
            state.selectedNoteFolderId = String(folder.id);
            saveDashboardCache();
            render();
        } catch (error) {
            state.error = friendlyError(error, 'create that folder');
            render();
        } finally {
            pendingNoteFolderNames.delete(nameKey);
        }
    }

    function bindNoteEditorAutosave() {
        const form = mount.querySelector('[data-note-editor]');
        if (!form) return;
        form.addEventListener('submit', (event) => {
            event.preventDefault();
            flushNoteAutosave(form);
        });
        const note = findById(state.notes, form.dataset.noteEditor);
        mountNoteMarkdownEditor(form, note);
        if (noteIsLocked(note)) return;
        form.querySelector('[name="title"]')?.addEventListener('input', () => scheduleNoteAutosave(form));
        form.querySelector('[name="title"]')?.addEventListener('blur', () => flushNoteAutosave(form));
        form.querySelector('[name="note_folder_id"]')?.addEventListener('change', () => scheduleNoteAutosave(form, true));
    }

    async function mountNoteMarkdownEditor(form, note) {
        const host = form.querySelector('[data-note-markdown-editor]');
        const source = form.querySelector('[data-note-markdown-source]');
        if (!host || !note) return;
        const initialValue = source?.value || '';
        const locked = noteIsLocked(note);
        host.setAttribute('aria-busy', 'true');
        let Editor;
        try {
            noteMarkdownEditorConstructorPromise ??= import('./noteMarkdownEditor.js').then((module) => module.default);
            Editor = await noteMarkdownEditorConstructorPromise;
        } catch (_) {
            if (form.isConnected) host.innerHTML = '<p class="hb-note-editor-load-error">The editor could not load. Refresh and try again.</p>';
            return;
        }
        if (!form.isConnected || String(form.dataset.noteEditor || '') !== String(note.id)) return;
        host.removeAttribute('aria-busy');
        activeNoteMarkdownEditorId = String(note.id);

        if (locked) {
            activeNoteMarkdownEditor = Editor.factory({
                el: host,
                viewer: true,
                initialValue,
                usageStatistics: false,
            });
            return;
        }

        let ready = false;
        const editor = new Editor({
            el: host,
            initialValue,
            initialEditType: 'wysiwyg',
            height: 'auto',
            minHeight: '420px',
            hideModeSwitch: true,
            usageStatistics: false,
            useCommandShortcut: true,
            extendedAutolinks: true,
            placeholder: 'Start writing…',
            toolbarItems: [
                ['heading', 'bold', 'italic', 'strike'],
                ['hr', 'quote'],
                ['ul', 'ol', 'task', 'indent', 'outdent'],
                ['table', 'image', 'link'],
                ['code', 'codeblock'],
            ],
            events: {
                change: () => {
                    if (ready) scheduleNoteAutosave(form);
                },
                blur: () => {
                    if (ready) flushNoteAutosave(form);
                },
            },
            hooks: {
                addImageBlobHook: (blob, callback) => embedNoteImage(blob, callback),
            },
        });
        activeNoteMarkdownEditor = editor;
        labelNoteToolbarOverflow(host);
        ready = true;
    }

    function labelNoteToolbarOverflow(host) {
        const labelButton = () => {
            const button = host.querySelector('.toastui-editor-toolbar-icons.more');
            if (!button) return false;
            button.setAttribute('aria-label', 'More formatting');
            button.setAttribute('title', 'More formatting');
            return true;
        };
        labelButton();
        const observer = new MutationObserver(() => {
            labelButton();
        });
        observer.observe(host, { childList: true, subtree: true });
        window.setTimeout(() => observer.disconnect(), 2000);
    }

    function destroyActiveNoteMarkdownEditor() {
        activeNoteMarkdownEditor?.destroy?.();
        activeNoteMarkdownEditor = null;
        activeNoteMarkdownEditorId = '';
    }

    function embedNoteImage(blob, callback) {
        if (!String(blob?.type || '').startsWith('image/')) {
            window.alert('Choose an image file to add it to this note.');
            return;
        }
        if (blob.size > 5 * 1024 * 1024) {
            window.alert('Images in notes must be 5 MB or smaller.');
            return;
        }
        const reader = new FileReader();
        reader.addEventListener('load', () => callback(String(reader.result || ''), blob.name || 'Image'));
        reader.readAsDataURL(blob);
    }

    function activeNotePlainText() {
        const editorRoot = activeNoteMarkdownEditor?.getEditorElements?.().wwEditor;
        return String(editorRoot?.innerText || editorRoot?.textContent || '')
            .replace(/\u00a0/g, ' ')
            .replace(/\n{3,}/g, '\n\n')
            .trim();
    }

    function notePayloadFromForm(form) {
        const source = form.querySelector('[data-note-markdown-source]');
        const noteId = String(form.dataset.noteEditor || '');
        const markdown = activeNoteMarkdownEditorId === noteId && activeNoteMarkdownEditor?.getMarkdown
            ? activeNoteMarkdownEditor.getMarkdown()
            : String(source?.value || '');
        return {
            title: String(form.elements.title?.value || '').trim() || 'New Note',
            body_markdown: markdown,
            note_folder_id: form.elements.note_folder_id?.value ? Number(form.elements.note_folder_id.value) : null,
        };
    }

    function scheduleNoteAutosave(form, immediate = false) {
        const id = form?.dataset?.noteEditor;
        const note = findById(state.notes, id);
        if (!id || noteIsLocked(note)) return;
        const body = notePayloadFromForm(form);
        state.notes = normalizeNotes(upsertById(state.notes, {
            ...note,
            ...body,
            plain_text: activeNotePlainText(),
            noteFolderId: body.note_folder_id,
        }));
        saveDashboardCache();
        setNoteSaveStatus(id, 'Saving…');
        const queued = noteAutosaveTimers.get(String(id));
        if (queued?.timer) window.clearTimeout(queued.timer);
        const timer = window.setTimeout(() => saveNotePayload(id, body), immediate ? 1 : noteAutosaveDelay);
        noteAutosaveTimers.set(String(id), { timer, body });
    }

    function flushNoteAutosave(form) {
        const id = form?.dataset?.noteEditor;
        if (!id) return;
        const queued = noteAutosaveTimers.get(String(id));
        if (queued?.timer) window.clearTimeout(queued.timer);
        const body = form.isConnected ? notePayloadFromForm(form) : queued?.body;
        if (body) saveNotePayload(id, body);
    }

    function flushAllNoteAutosaves(options = {}) {
        noteAutosaveTimers.forEach((queued, id) => {
            if (queued?.timer) window.clearTimeout(queued.timer);
            if (queued?.body) saveNotePayload(id, queued.body, options);
        });
    }

    function dailyStickyNoteKey(date = state.selectedDay, workspaceId = currentWorkspaceId()) {
        return `${String(workspaceId || 'none')}:${String(date || '')}`;
    }

    function bindDailyStickyNoteActions() {
        const textarea = mount.querySelector('[data-daily-sticky-note]');
        if (!textarea) return;
        textarea.addEventListener('input', () => scheduleDailyStickyNoteAutosave(textarea));
        textarea.addEventListener('blur', () => flushDailyStickyNoteAutosave(textarea.dataset.stickyNoteKey));
        ensureDailyStickyNoteLoaded(
            textarea.dataset.stickyNoteDate,
            textarea.dataset.stickyNoteWorkspace,
        );
    }

    async function ensureDailyStickyNoteLoaded(date, workspaceId) {
        const key = dailyStickyNoteKey(date, workspaceId);
        if (!date || !workspaceId || state.dailyStickyNoteLoadedKeys.has(key) || state.dailyStickyNoteLoadingKeys.has(key)) return;
        state.dailyStickyNoteLoadingKeys.add(key);
        try {
            const note = await api(workspaceScopedPath(`/daily-sticky-note?date=${encodeURIComponent(date)}`, workspaceId));
            state.dailyStickyNotes.set(key, String(note?.content || ''));
            state.dailyStickyNoteLoadedKeys.add(key);
            state.dailyStickyNoteStatuses.set(key, '');
            updateDailyStickyNoteDom(key);
        } catch (error) {
            state.dailyStickyNoteStatuses.set(key, 'Couldn’t load');
            setDailyStickyNoteStatus(key, 'Couldn’t load');
        } finally {
            state.dailyStickyNoteLoadingKeys.delete(key);
        }
    }

    function updateDailyStickyNoteDom(key) {
        const textarea = mount.querySelector('[data-daily-sticky-note]');
        if (!textarea || textarea.dataset.stickyNoteKey !== key) return;
        textarea.value = state.dailyStickyNotes.get(key) || '';
        textarea.disabled = false;
        textarea.closest('[data-daily-sticky-note-shell]')?.classList.remove('hb-daily-sticky-note-loading');
        setDailyStickyNoteStatus(key, state.dailyStickyNoteStatuses.get(key) || '');
    }

    function scheduleDailyStickyNoteAutosave(textarea, immediate = false) {
        const key = textarea?.dataset?.stickyNoteKey;
        const date = textarea?.dataset?.stickyNoteDate;
        const workspaceId = textarea?.dataset?.stickyNoteWorkspace;
        if (!key || !date || !workspaceId) return;
        const body = {
            date,
            content: String(textarea.value || '').slice(0, 12000),
            workspace_id: Number(workspaceId),
        };
        state.dailyStickyNotes.set(key, body.content);
        setDailyStickyNoteStatus(key, 'Saving');
        const queued = dailyStickyNoteAutosaveTimers.get(key);
        if (queued?.timer) window.clearTimeout(queued.timer);
        const timer = window.setTimeout(
            () => saveDailyStickyNotePayload(key, body),
            immediate ? 1 : dailyStickyNoteAutosaveDelay,
        );
        dailyStickyNoteAutosaveTimers.set(key, { timer, body });
    }

    function flushDailyStickyNoteAutosave(key, options = {}) {
        if (!key) return;
        const queued = dailyStickyNoteAutosaveTimers.get(key);
        if (queued?.timer) window.clearTimeout(queued.timer);
        if (queued?.body) saveDailyStickyNotePayload(key, queued.body, options);
    }

    function flushAllDailyStickyNoteAutosaves(options = {}) {
        dailyStickyNoteAutosaveTimers.forEach((queued, key) => {
            if (queued?.timer) window.clearTimeout(queued.timer);
            if (queued?.body) saveDailyStickyNotePayload(key, queued.body, options);
        });
    }

    async function saveDailyStickyNotePayload(key, body, options = {}) {
        if (!key || !body) return;
        dailyStickyNoteAutosaveTimers.delete(key);
        if (dailyStickyNoteSaveInFlight.has(key) && !options.keepalive) {
            pendingDailyStickyNoteBodies.set(key, body);
            return;
        }
        dailyStickyNoteSaveInFlight.add(key);
        let saveFailed = false;
        try {
            const note = await api('/daily-sticky-note', {
                method: 'PUT',
                body,
                keepalive: options.keepalive === true,
            });
            if (state.dailyStickyNotes.get(key) === body.content) {
                state.dailyStickyNotes.set(key, String(note?.content || ''));
            }
            state.dailyStickyNoteLoadedKeys.add(key);
        } catch (error) {
            saveFailed = true;
        } finally {
            dailyStickyNoteSaveInFlight.delete(key);
            const pendingBody = pendingDailyStickyNoteBodies.get(key);
            pendingDailyStickyNoteBodies.delete(key);
            if (pendingBody) {
                setDailyStickyNoteStatus(key, 'Saving');
                saveDailyStickyNotePayload(key, pendingBody);
            } else if (saveFailed && !options.keepalive) {
                setDailyStickyNoteStatus(key, 'Couldn’t save');
                const timer = window.setTimeout(() => saveDailyStickyNotePayload(key, body), 3000);
                dailyStickyNoteAutosaveTimers.set(key, { timer, body });
            } else if (!saveFailed) {
                const newerSaveQueued = dailyStickyNoteAutosaveTimers.has(key)
                    || state.dailyStickyNotes.get(key) !== body.content;
                setDailyStickyNoteStatus(key, newerSaveQueued ? 'Saving' : 'Saved');
            }
        }
    }

    function setDailyStickyNoteStatus(key, text) {
        state.dailyStickyNoteStatuses.set(key, text);
        const existingTimer = dailyStickyNoteStatusFadeTimers.get(key);
        if (existingTimer) window.clearTimeout(existingTimer);
        dailyStickyNoteStatusFadeTimers.delete(key);

        const textarea = mount.querySelector('[data-daily-sticky-note]');
        const status = mount.querySelector('[data-daily-sticky-note-status]');
        if (textarea?.dataset?.stickyNoteKey === key && status) {
            status.textContent = text;
            status.classList.toggle('hb-daily-sticky-note-status-visible', Boolean(text));
            status.classList.toggle('hb-daily-sticky-note-status-error', text.includes('Couldn’t'));
        }

        if (text !== 'Saved') return;
        const timer = window.setTimeout(() => {
            state.dailyStickyNoteStatuses.set(key, '');
            dailyStickyNoteStatusFadeTimers.delete(key);
            const currentTextarea = mount.querySelector('[data-daily-sticky-note]');
            const currentStatus = mount.querySelector('[data-daily-sticky-note-status]');
            if (currentTextarea?.dataset?.stickyNoteKey === key && currentStatus) {
                currentStatus.classList.remove('hb-daily-sticky-note-status-visible');
            }
        }, 10000);
        dailyStickyNoteStatusFadeTimers.set(key, timer);
    }

    function setNoteSaveStatus(id, text) {
        const form = mount.querySelector('[data-note-editor]');
        if (String(form?.dataset.noteEditor || '') !== String(id || '')) return;
        const status = mount.querySelector('.hb-note-save-state');
        if (!status) return;
        window.clearTimeout(noteSaveStatusFadeTimer);
        status.textContent = text;
        status.classList.toggle('hb-note-save-state-visible', Boolean(text));
        status.classList.toggle('hb-note-save-state-error', text === 'Retrying…');
        if (text !== 'Saved') return;
        noteSaveStatusFadeTimer = window.setTimeout(() => {
            status.classList.remove('hb-note-save-state-visible');
        }, 2500);
    }

    async function saveNotePayload(id, body, options = {}) {
        if (!id) return;
        noteAutosaveTimers.delete(String(id));
        if (noteSaveInFlight.has(String(id)) && !options.keepalive) {
            pendingNoteSaveBodies.set(String(id), body);
            return;
        }
        noteSaveInFlight.add(String(id));
        state.notesSaving = true;
        state.error = '';
        setNoteSaveStatus(id, 'Saving…');
        let saveFailed = false;
        try {
            const note = await api(`/notes/${encodeURIComponent(id)}`, {
                method: 'PATCH',
                body,
                keepalive: options.keepalive === true,
            });
            state.notes = normalizeNotes(upsertById(state.notes, note));
            state.selectedNoteId = String(note.id);
            saveDashboardCache();
        } catch (error) {
            saveFailed = true;
            state.notes = normalizeNotes(upsertById(state.notes, {
                ...findById(state.notes, id),
                ...body,
            }));
            saveDashboardCache();
        } finally {
            noteSaveInFlight.delete(String(id));
            state.notesSaving = false;
            const pendingBody = pendingNoteSaveBodies.get(String(id));
            pendingNoteSaveBodies.delete(String(id));
            if (pendingBody) {
                saveNotePayload(id, pendingBody);
            } else if (saveFailed && !options.keepalive) {
                setNoteSaveStatus(id, 'Retrying…');
                const timer = window.setTimeout(() => saveNotePayload(id, body), 3000);
                noteAutosaveTimers.set(String(id), { timer, body });
            } else if (!saveFailed) {
                setNoteSaveStatus(id, 'Saved');
            }
        }
    }

    async function toggleNotePin(id) {
        const note = findById(state.notes, id);
        if (!note) return;
        const next = !(note.is_pinned || note.isPinned);
        state.notes = normalizeNotes(upsertById(state.notes, { ...note, is_pinned: next, isPinned: next }));
        render();
        try {
            const saved = await api(`/notes/${encodeURIComponent(id)}`, { method: 'PATCH', body: { is_pinned: next } });
            state.notes = normalizeNotes(upsertById(state.notes, saved));
            saveDashboardCache();
            render();
        } catch (error) {
            state.notes = normalizeNotes(upsertById(state.notes, note));
            state.error = friendlyError(error, 'pin that note');
            render();
        }
    }

    async function toggleNoteLock(id) {
        const note = findById(state.notes, id);
        if (!note) return;
        const currentForm = Array.from(mount.querySelectorAll('[data-note-editor]'))
            .find((form) => String(form.dataset.noteEditor) === String(id));
        if (currentForm && !noteIsLocked(note)) flushNoteAutosave(currentForm);
        const metadata = normalizeNoteMetadata(note.metadata);
        const nextLocked = !noteIsLocked(note);
        const updatedMetadata = { ...metadata, locked: nextLocked };
        state.notes = normalizeNotes(upsertById(state.notes, { ...note, metadata: updatedMetadata }));
        render();
        try {
            const saved = await api(`/notes/${encodeURIComponent(id)}`, { method: 'PATCH', body: { metadata: updatedMetadata } });
            state.notes = normalizeNotes(upsertById(state.notes, saved));
            saveDashboardCache();
            render();
        } catch (error) {
            state.notes = normalizeNotes(upsertById(state.notes, note));
            state.error = friendlyError(error, nextLocked ? 'lock that note' : 'unlock that note');
            render();
        }
    }

    async function moveNoteFolder(id) {
        const note = findById(state.notes, id);
        if (!note) return;
        const choices = [
            { id: '', name: 'All Notes' },
            ...normalizeList(state.noteFolders).map((folder) => ({ id: String(folder.id), name: folder.name })),
        ];
        const label = choices.map((choice, index) => `${index + 1}. ${choice.name}`).join('\n');
        const choice = window.prompt(`Move note to folder:\n${label}`, '1');
        if (!choice) return;
        const selected = choices[Number(choice) - 1];
        if (!selected) return;
        await saveNotePayload(id, {
            note_folder_id: selected.id ? Number(selected.id) : null,
        });
    }

    async function updateNoteWorkspaceSync(form) {
        const id = form?.dataset?.noteEditor;
        if (!id) return;
        const selected = Array.from(form.querySelectorAll('[data-note-sync-workspace]:checked'))
            .map((input) => Number(input.dataset.noteSyncWorkspace))
            .filter((value) => Number.isFinite(value));
        await saveNotePayload(id, { sync_to_workspace_ids: selected });
    }

    async function deleteNote(id) {
        if (!id || !window.confirm('Delete this note?')) return;
        const previous = state.notes;
        state.notes = state.notes.filter((note) => String(note.id) !== String(id));
        ensureSelectedNote();
        state.notesDetailOpen = false;
        render();
        try {
            await api(`/notes/${encodeURIComponent(id)}`, { method: 'DELETE' });
            saveDashboardCache();
        } catch (error) {
            state.notes = previous;
            state.error = friendlyError(error, 'delete that note');
            render();
        }
    }

    async function deleteNoteFolder(id) {
        if (!id || !window.confirm('Delete this folder? Notes inside it will stay in All Notes.')) return;
        try {
            await api(`/note-folders/${encodeURIComponent(id)}`, { method: 'DELETE' });
            state.noteFolders = normalizeNoteFolders(state.noteFolders.filter((folder) => String(folder.id) !== String(id)));
            state.notes = state.notes.map((note) => String(note.note_folder_id || note.noteFolderId || '') === String(id)
                ? { ...note, note_folder_id: null, noteFolderId: null }
                : note);
            if (String(state.selectedNoteFolderId) === String(id)) state.selectedNoteFolderId = 'all';
            ensureSelectedNote();
            saveDashboardCache();
            render();
        } catch (error) {
            state.error = friendlyError(error, 'delete that folder');
            render();
        }
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
        mount.querySelector('[data-post-signup-tour]')?.addEventListener('click', () => {
            startPostSignupDashboardTour();
            render();
        });
        mount.querySelector('[data-post-signup-first-action]')?.addEventListener('click', () => {
            startPostSignupFirstActionChoice();
            render();
        });
        mount.querySelector('[data-post-signup-skip]')?.addEventListener('click', () => {
            finishPostTourFirstAction();
            render();
        });
        mount.querySelectorAll('[data-post-tour-first-action]').forEach((button) => button.addEventListener('click', () => {
            state.modal = { type: 'post-tour-first-action', step: 'assist', action: button.dataset.postTourFirstAction };
            render();
        }));
        mount.querySelector('[data-post-tour-bean-do-it]')?.addEventListener('click', (event) => {
            askBeanToStartPostTourAction(event.currentTarget.dataset.postTourBeanDoIt);
        });
        mount.querySelector('[data-post-tour-walkthrough]')?.addEventListener('click', (event) => {
            walkThroughPostTourAction(event.currentTarget.dataset.postTourWalkthrough);
            render();
        });
        mount.querySelector('[data-post-tour-first-action-skip]')?.addEventListener('click', () => {
            finishPostTourFirstAction();
            render();
        });
        mount.querySelectorAll('[data-modal-delete]').forEach((button) => button.addEventListener('click', deleteModalItem));
        mount.querySelectorAll('[data-recurring-delete-mode]').forEach((button) => button.addEventListener('click', confirmRecurringDelete));
        mount.querySelector('[data-modal-form]')?.addEventListener('submit', submitModal);
        mount.querySelector('[data-external-calendar-import-provider]')?.addEventListener('change', (event) => {
            state.modal = {
                ...(state.modal || {}),
                providerKey: event.currentTarget.value,
                error: '',
            };
            render();
        });
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
        mount.querySelectorAll('[data-date-time-picker]').forEach(bindDateTimePicker);
        mount.querySelectorAll('form[data-modal-form="event"]').forEach(bindEventTimeInputs);
        mount.querySelectorAll('form[data-modal-form="event"]').forEach(bindEventLocationInput);
        mount.querySelectorAll('input[name="workspaceAssignmentIds"]').forEach((input) => input.addEventListener('change', handleWorkspaceAssignmentChange));
        mount.querySelectorAll('[data-recurrence-select]').forEach((select) => {
            select.addEventListener('change', () => toggleRecurrenceFields(select.closest('form')));
            toggleRecurrenceFields(select.closest('form'));
        });
        mount.querySelectorAll('[data-event-reminder-toggle]').forEach((checkbox) => {
            checkbox.addEventListener('change', () => toggleEventReminderFields(checkbox.closest('form')));
            toggleEventReminderFields(checkbox.closest('form'));
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
                if (state.modal?.type === 'post-tour-first-action' && openDeferredSignupPaywall('Choose a plan to continue into your dashboard.')) {
                    return;
                }
                state.modal = null;
                render();
            }
        });
    }

    function handleWorkspaceAssignmentChange(event) {
        const input = event.currentTarget;
        const form = input.closest('form');
        ensureWorkspaceAssignmentSelected(form, input);
        refreshEventWorkspaceOptions(form);
        refreshReminderRecipientOptions(form);
    }

    function ensureWorkspaceAssignmentSelected(form, fallbackInput = null) {
        if (!form) return;
        const checked = form.querySelectorAll('input[name="workspaceAssignmentIds"]:checked');
        if (checked.length || !fallbackInput) return;
        fallbackInput.checked = true;
    }

    function refreshEventWorkspaceOptions(form) {
        if (!form || form.dataset.modalForm !== 'event') return;
    }

    function refreshReminderRecipientOptions(form) {
        if (!form || form.dataset.modalForm !== 'reminder') return;
        const container = form.querySelector('[data-reminder-recipient-options]');
        const picker = form.querySelector('[data-workspace-picker]');
        if (!container || !picker) return;
        container.innerHTML = reminderRecipientOptionsMarkup(selectedWorkspaceAssignmentIds(form), state.modal?.item, selectedReminderRecipientsByWorkspace(form));
    }

    function toggleRecurrenceFields(form) {
        if (!form) return;
        const recurrence = form.querySelector('[data-recurrence-select]')?.value || 'none';
        setFieldGroupState(form.querySelector('[data-recurrence-days]'), recurrence === 'specific_days');
        setFieldGroupState(form.querySelector('[data-recurrence-interval]'), recurrence === 'interval');
    }

    function toggleEventReminderFields(form) {
        if (!form) return;
        const enabled = Boolean(form.querySelector('[data-event-reminder-toggle]')?.checked);
        setFieldGroupState(form.querySelector('[data-event-reminder-fields]'), enabled);
    }

    function bindEventLocationInput(form) {
        const field = form?.querySelector('[data-event-location-field]');
        const input = field?.querySelector('[data-event-location-input]');
        if (!field || !input) return;
        const suggestions = field.querySelector('[data-location-suggestions]');
        const status = field.querySelector('[data-location-status]');
        const directions = field.querySelector('[data-location-directions]');
        let timer = null;
        let sessionToken = `${Date.now()}-${Math.random().toString(36).slice(2)}`;

        const selectedAddress = field.querySelector('[data-event-place-address]')?.value || '';
        if (selectedAddress && field.querySelector('[data-event-place-lat]')?.value && field.querySelector('[data-event-place-lng]')?.value) {
            refreshEventLocationMapPreview(field);
        }

        input.addEventListener('input', () => {
            const value = input.value.trim();
            const savedAddress = field.querySelector('[data-event-place-address]')?.value || '';
            if (value !== savedAddress) clearEventPlaceFields(field);
            window.clearTimeout(timer);
            hideLocationStatus(field);
            if (value.length < 3) {
                hideLocationSuggestions(field);
                setLocationMapVisible(field, false);
                return;
            }
            timer = window.setTimeout(() => searchEventLocations(field, value, sessionToken), 300);
        });

        suggestions?.addEventListener('click', (event) => {
            const button = event.target.closest('[data-place-id]');
            if (!button) return;
            selectEventLocationSuggestion(field, button.dataset.placeId, sessionToken);
            sessionToken = `${Date.now()}-${Math.random().toString(36).slice(2)}`;
        });

        directions?.addEventListener('click', () => {
            const address = input.value.trim();
            if (!address) return;
            window.open(eventDirectionsUrl(address, field.querySelector('[data-event-place-id]')?.value || ''), '_blank', 'noopener');
        });
    }

    async function searchEventLocations(field, query, sessionToken) {
        setLocationStatus(field, 'Searching locations...');
        try {
            const result = await api(`/places/autocomplete?input=${encodeURIComponent(query)}&session_token=${encodeURIComponent(sessionToken)}`, { timeoutMs: 8000 });
            const suggestions = normalizeList(result?.suggestions);
            if (!suggestions.length) {
                hideLocationSuggestions(field);
                setLocationStatus(field, result?.enabled === false ? 'Location search is not configured yet.' : 'No matching locations found.');
                return;
            }
            const container = field.querySelector('[data-location-suggestions]');
            if (!container) return;
            container.innerHTML = suggestions.map((suggestion) => `
                <button class="hb-location-suggestion" type="button" data-place-id="${escapeAttr(suggestion.place_id || suggestion.placeId)}">
                    <strong>${escapeHtml(suggestion.primary_text || suggestion.primaryText || suggestion.full_text || suggestion.fullText || 'Location')}</strong>
                    ${suggestion.secondary_text || suggestion.secondaryText ? `<small>${escapeHtml(suggestion.secondary_text || suggestion.secondaryText)}</small>` : ''}
                </button>
            `).join('');
            container.hidden = false;
            hideLocationStatus(field);
        } catch (error) {
            hideLocationSuggestions(field);
            setLocationStatus(field, friendlyError(error, 'load location suggestions'));
        }
    }

    async function selectEventLocationSuggestion(field, placeId, sessionToken) {
        if (!placeId) return;
        setLocationStatus(field, 'Loading location...');
        hideLocationSuggestions(field);
        try {
            const place = await api(`/places/details?place_id=${encodeURIComponent(placeId)}&session_token=${encodeURIComponent(sessionToken)}`, { timeoutMs: 8000 });
            const address = place.formatted_address || place.formattedAddress || place.name || '';
            field.querySelector('[data-event-location-input]').value = address;
            field.querySelector('[data-event-place-id]').value = place.place_id || place.placeId || placeId;
            field.querySelector('[data-event-place-address]').value = address;
            field.querySelector('[data-event-place-lat]').value = place.latitude ?? '';
            field.querySelector('[data-event-place-lng]').value = place.longitude ?? '';
            field.querySelector('[data-event-google-maps-uri]').value = place.google_maps_uri || place.googleMapsUri || '';
            hideLocationStatus(field);
            refreshEventLocationMapPreview(field);
        } catch (error) {
            setLocationStatus(field, friendlyError(error, 'load that location'));
        }
    }

    async function refreshEventLocationMapPreview(field) {
        const lat = Number.parseFloat(field.querySelector('[data-event-place-lat]')?.value || '');
        const lng = Number.parseFloat(field.querySelector('[data-event-place-lng]')?.value || '');
        const address = field.querySelector('[data-event-place-address]')?.value || field.querySelector('[data-event-location-input]')?.value || '';
        if (!Number.isFinite(lat) || !Number.isFinite(lng)) {
            setLocationMapVisible(field, false);
            return;
        }
        const map = field.querySelector('[data-location-map]');
        const image = field.querySelector('[data-location-map-image]');
        const addressNode = field.querySelector('[data-location-map-address]');
        if (!map || !image) return;
        if (addressNode) addressNode.textContent = address;
        map.hidden = false;
        image.style.backgroundImage = '';
        image.replaceChildren();
        renderLocationMapEmbed(image, lat, lng, address);
        try {
            const response = await fetchWithTimeout(`/api/places/static-map?lat=${encodeURIComponent(lat)}&lng=${encodeURIComponent(lng)}&theme=${resolvedThemeMode()}`, {
                headers: {
                    Accept: 'image/png',
                    ...(state.token ? { Authorization: `Bearer ${state.token}` } : {}),
                },
            }, 10000);
            if (!response.ok) throw new Error('Map preview failed.');
            const blob = await response.blob();
            const url = URL.createObjectURL(blob);
            await waitForImageLoad(url);
            image.replaceChildren();
            image.style.backgroundImage = `url("${url}")`;
        } catch (_) {
            if (!image.querySelector('.hb-location-map-frame')) {
                image.style.backgroundImage = '';
                renderLocationMapEmbed(image, lat, lng, address);
            }
        }
    }

    function waitForImageLoad(url) {
        return new Promise((resolve, reject) => {
            const image = new Image();
            image.onload = () => resolve(true);
            image.onerror = () => reject(new Error('Map image failed to load.'));
            image.src = url;
        });
    }

    function renderLocationMapEmbed(container, lat, lng, address = '') {
        if (!container) return;
        const query = address || `${lat},${lng}`;
        const iframe = document.createElement('iframe');
        iframe.className = 'hb-location-map-frame';
        iframe.loading = 'lazy';
        iframe.referrerPolicy = 'no-referrer-when-downgrade';
        iframe.title = address ? `Map preview for ${address}` : 'Map preview';
        iframe.src = `https://maps.google.com/maps?q=${encodeURIComponent(query)}&z=15&output=embed`;
        container.replaceChildren(iframe);
    }

    function clearEventPlaceFields(field) {
        ['[data-event-place-id]', '[data-event-place-address]', '[data-event-place-lat]', '[data-event-place-lng]', '[data-event-google-maps-uri]'].forEach((selector) => {
            const input = field.querySelector(selector);
            if (input) input.value = '';
        });
    }

    function eventDirectionsUrl(address, placeId = '') {
        const preferredMapApp = String(state.user?.preferred_map_app || state.user?.preferredMapApp || 'google') === 'apple' ? 'apple' : 'google';
        if (preferredMapApp === 'apple') {
            return `https://maps.apple.com/?q=${encodeURIComponent(address)}`;
        }
        const query = new URLSearchParams({ api: '1', query: address });
        if (placeId) query.set('query_place_id', placeId);
        return `https://www.google.com/maps/search/?${query.toString()}`;
    }

    function hideLocationSuggestions(field) {
        const container = field.querySelector('[data-location-suggestions]');
        if (!container) return;
        container.hidden = true;
        container.innerHTML = '';
    }

    function setLocationStatus(field, message) {
        const status = field.querySelector('[data-location-status]');
        if (!status) return;
        status.hidden = !message;
        status.textContent = message;
    }

    function hideLocationStatus(field) {
        setLocationStatus(field, '');
    }

    function setLocationMapVisible(field, visible) {
        const map = field.querySelector('[data-location-map]');
        if (map) map.hidden = !visible;
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
        const colorField = form?.querySelector('[data-no-category-color-field]');
        const current = selectedName === null ? select?.value || '' : selectedName;
        if (select) {
            select.innerHTML = categoryOptions(current)
                .map((category) => `<option value="${escapeAttr(category.name)}" data-category-color="${escapeAttr(safeColor(category.color))}" ${category.name === current ? 'selected' : ''}>${escapeHtml(category.name)}</option>`)
                .join('');
            select.insertAdjacentHTML('afterbegin', `<option value="" data-category-color="${escapeAttr(themeAccentColor())}" ${current ? '' : 'selected'}>None</option>`);
            select.value = current;
        }
        if (colorInput) {
            colorInput.value = current
                ? safeColor(selectedColor || categoryColor(current))
                : safeColor(colorInput.dataset.noCategoryColor || themeAccentColor());
        }
        if (colorField) {
            colorField.hidden = Boolean(current);
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

    function bindDateTimePicker(picker) {
        const input = picker.querySelector('[data-date-time-value]');
        const trigger = picker.querySelector('[data-date-time-trigger]');
        const panel = picker.querySelector('[data-date-time-panel]');
        if (!input || !trigger || !panel) return;
        const close = () => {
            panel.hidden = true;
            trigger.setAttribute('aria-expanded', 'false');
        };
        trigger.addEventListener('click', () => {
            if (input.disabled) return;
            const open = panel.hidden;
            mount.querySelectorAll('[data-date-time-panel]').forEach((candidate) => {
                if (candidate !== panel) {
                    candidate.hidden = true;
                    candidate.closest('[data-date-time-picker]')?.querySelector('[data-date-time-trigger]')?.setAttribute('aria-expanded', 'false');
                }
            });
            panel.hidden = !open;
            trigger.setAttribute('aria-expanded', String(open));
        });
        panel.addEventListener('click', (event) => {
            const monthButton = event.target.closest('[data-date-time-month]');
            if (monthButton) {
                refreshDateTimePickerPanel(picker, monthButton.dataset.dateTimeMonth);
                return;
            }
            const dayButton = event.target.closest('[data-date-time-day]');
            if (dayButton) {
                setDateTimePickerDate(picker, dayButton.dataset.dateTimeDay);
                return;
            }
            if (event.target.closest('[data-date-time-done]')) {
                close();
            }
        });
        panel.addEventListener('change', (event) => {
            if (event.target.matches('[data-date-time-hour], [data-date-time-minute], [data-date-time-meridiem]')) {
                setDateTimePickerTimeFromControls(picker);
            }
        });
        input.addEventListener('change', () => refreshDateTimePickerDisplay(picker));
    }

    function setDateTimePickerDate(picker, dayValue) {
        const input = picker?.querySelector('[data-date-time-value]');
        if (!input || !dayValue) return;
        const mode = picker.dataset.pickerMode || 'datetime-local';
        if (mode === 'date') {
            setDateTimePickerValue(input, dayValue, { dispatch: true });
            refreshDateTimePickerPanel(picker, dayValue);
            return;
        }
        const current = dateTimePickerDate(input.value, mode);
        const date = parseLocalDate(dayValue);
        date.setHours(current.getHours(), current.getMinutes(), 0, 0);
        setDateTimePickerValue(input, toDatetimeLocal(date), { dispatch: true });
        refreshDateTimePickerPanel(picker, dayValue);
    }

    function setDateTimePickerTimeFromControls(picker) {
        const input = picker?.querySelector('[data-date-time-value]');
        if (!input) return;
        const date = dateTimePickerDate(input.value, 'datetime-local');
        const hour12 = Number(picker.querySelector('[data-date-time-hour]')?.value || 12);
        const minute = Number(picker.querySelector('[data-date-time-minute]')?.value || 0);
        const meridiem = picker.querySelector('[data-date-time-meridiem]')?.value || 'AM';
        let hour = hour12 % 12;
        if (meridiem === 'PM') hour += 12;
        date.setHours(hour, minute, 0, 0);
        setDateTimePickerValue(input, toDatetimeLocal(date), { dispatch: true });
        refreshDateTimePickerPanel(picker, dateOnly(date));
    }

    function setDateTimePickerValue(input, value, options = {}) {
        if (!input) return;
        const picker = input.closest('[data-date-time-picker]');
        const mode = picker?.dataset.pickerMode || input.dataset.pickerMode || 'datetime-local';
        input.value = normalizeDateTimePickerValue(value, mode);
        refreshDateTimePickerDisplay(picker);
        if (options.refreshPanel !== false && picker) {
            refreshDateTimePickerPanel(picker, input.value ? dateOnly(input.value) : '');
        }
        if (options.dispatch) {
            input.dispatchEvent(new Event('change', { bubbles: true }));
        }
    }

    function refreshDateTimePickerDisplay(picker) {
        if (!picker) return;
        const input = picker.querySelector('[data-date-time-value]');
        const display = picker.querySelector('[data-date-time-display]');
        if (!input || !display) return;
        display.textContent = dateTimePickerDisplay(input.value, picker.dataset.pickerMode || 'datetime-local');
    }

    function refreshDateTimePickerPanel(picker, visibleMonthValue = '') {
        const panel = picker?.querySelector('[data-date-time-panel]');
        const input = picker?.querySelector('[data-date-time-value]');
        if (!panel || !input) return;
        panel.innerHTML = dateTimePickerPanelMarkup(input.value, picker.dataset.pickerMode || 'datetime-local', visibleMonthValue);
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
        if (allDayStart) {
            allDayStart.addEventListener('change', () => {
                if (!allDayEnd) return;
                const reconciledEnd = reconcileAllDayEndDateInput(
                    allDayStart.value,
                    allDayEnd.value,
                );
                if (reconciledEnd !== allDayEnd.value) {
                    setDateTimePickerValue(allDayEnd, reconciledEnd, { dispatch: false });
                }
            });
        }
        if (allDayEnd) {
            allDayEnd.addEventListener('change', () => {
                const reconciledEnd = reconcileAllDayEndDateInput(
                    allDayStart?.value,
                    allDayEnd.value,
                );
                if (reconciledEnd !== allDayEnd.value) {
                    setDateTimePickerValue(allDayEnd, reconciledEnd, { dispatch: false });
                }
            });
        }
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
            setDateTimePickerValue(endInput, toDatetimeLocal(addMinutes(startInput.value, Number.isFinite(duration) ? duration : 60)), {
                dispatch: false,
            });
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
                state.user = await api('/auth/me', {
                    method: 'PATCH',
                    body: {
                        email: data.email,
                        preferred_map_app: data.preferredMapApp === 'apple' ? 'apple' : 'google',
                        timezone: String(data.timezone || '').trim(),
                    },
                });
            } else if (kind === 'issue-report') {
                await submitIssueReport(form);
                return;
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
            } else if (kind === 'external-calendar-import') {
                if (form.dataset.saving === 'true') return;
                form.dataset.saving = 'true';
                const provider = externalCalendarImportPreset(data.providerKey);
                const result = await api('/external-calendars/import', {
                    method: 'POST',
                    body: {
                        provider_key: provider.key,
                        url: String(data.url || '').trim(),
                        workspace_id: currentWorkspaceId() || null,
                    },
                    timeoutMs: 30000,
                });
                state.modal = null;
                state.notice = externalCalendarImportResultMessage(result, provider);
                state.error = '';
                render();
                refreshOnlyInBackground({ skipCalendarSync: true });
                return;
            } else if (kind === 'category-create') {
                await api('/event-categories', { method: 'POST', body: { name: data.name, color: data.color || themeAccentColor() } });
                await refreshOnly(false);
                state.modal = { type: 'categories' };
                render();
                return;
            } else if (kind === 'note-create') {
                await createNote(form);
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
            if (['task', 'reminder', 'event'].includes(kind)) {
                form.dataset.saving = 'false';
            } else if (kind === 'external-calendar-import') {
                form.dataset.saving = 'false';
                state.modal = {
                    ...(state.modal || { type: 'external-calendar-import' }),
                    error: friendlyError(error, 'import external calendar'),
                };
            } else {
                state.modal = null;
            }
            render();
        }
    }

    function externalCalendarImportResultMessage(result = {}, provider = externalCalendarImportPresets[0]) {
        const imported = Number(result.imported || 0);
        const updated = Number(result.updated || 0);
        const deleted = Number(result.deleted || 0);
        const skipped = Number(result.skipped || 0);
        const parts = [
            imported > 0 ? `${imported} new` : '',
            updated > 0 ? `${updated} updated` : '',
            deleted > 0 ? `${deleted} removed` : '',
            skipped > 0 ? `${skipped} skipped` : '',
        ].filter(Boolean);
        return `${parts.length ? parts.join(', ') : 'No changes'} from ${result.provider_label || result.providerLabel || provider.label}.`;
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
        if (!id || !status || state.adminLoading) return;
        state.adminLoading = true;
        state.error = '';
        render();
        try {
            const updated = await api(`/admin/issue-reports/${encodeURIComponent(id)}`, {
                method: 'PATCH',
                body: { status },
            });
            updateAdminIssueReportLocal(updated);
            state.adminLoading = false;
            render();
        } catch (error) {
            state.error = friendlyError(error, 'update that issue report');
            state.adminLoading = false;
            render();
        }
    }

    function updateAdminIssueReportLocal(report) {
        if (!state.adminIssueSummary || !report?.id) return;
        const openReports = normalizeList(state.adminIssueSummary.issue_reports || state.adminIssueSummary.issueReports);
        const archivedReports = normalizeList(state.adminIssueSummary.archived_issue_reports || state.adminIssueSummary.archivedIssueReports);
        const wasOpen = openReports.some((item) => String(item.id || '') === String(report.id));
        const wasArchived = archivedReports.some((item) => String(item.id || '') === String(report.id));
        const withoutReport = (items) => items.filter((item) => String(item.id || '') !== String(report.id));
        const openNext = withoutReport(openReports);
        const archivedNext = withoutReport(archivedReports);
        const status = String(report.status || 'open').toLowerCase();
        const totals = state.adminIssueSummary.totals || {};
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

        state.adminIssueSummary = {
            ...state.adminIssueSummary,
            totals: {
                ...totals,
                open_issue_reports: openCount,
                archived_issue_reports: archivedCount,
            },
            issue_reports: openNext,
            archived_issue_reports: archivedNext,
        };
    }

    async function loadAdminData(force = false) {
        if (!userIsAdmin() || (state.adminLoading && !force)) return;
        state.adminLoading = true;
        state.error = '';
        render();
        try {
            const growthRange = encodeURIComponent(state.adminUserGrowthRange || 'last_30_days');
            const [summary, issues, planLimits, coupons] = await Promise.all([
                api(`/admin/dashboard/summary?growth_range=${growthRange}`),
                api('/admin/issue-reports/summary'),
                api('/admin/plan-limits'),
                api('/admin/coupon-codes'),
            ]);
            state.adminDashboardSummary = summary;
            state.adminIssueSummary = issues;
            state.adminPlanLimits = planLimits;
            state.adminCoupons = normalizeList(coupons?.coupons || coupons);
        } catch (error) {
            state.error = friendlyError(error, 'load administration data');
        } finally {
            state.adminLoading = false;
            render();
        }
    }

    function setAdminUserGrowthRange(range) {
        if (!['today', 'last_7_days', 'last_30_days', 'all_time'].includes(range) || state.adminUserGrowthRange === range) return;
        state.adminUserGrowthRange = range;
        loadAdminData(true);
    }

    async function saveAdminPlanLimits(event) {
        event.preventDefault();
        const form = event.currentTarget;
        const plans = {};
        form.querySelectorAll('[data-plan-limit-card]').forEach((card) => {
            plans[card.dataset.planLimitCard] = readAdminLimits(card);
        });
        state.adminLoading = true;
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
            state.adminLoading = false;
            render();
        }
    }

    async function createAdminCouponCode(event) {
        event.preventDefault();
        const form = event.currentTarget;
        const formData = new FormData(form);
        const code = String(formData.get('code') || '').replace(/\D/g, '').slice(0, 6);
        const months = Number(formData.get('months_free_base') || 1);
        if (code && code.length !== 6) {
            state.error = 'Manual coupon codes must be exactly 6 digits.';
            render();
            return;
        }
        state.adminLoading = true;
        state.error = '';
        render();
        try {
            await api('/admin/coupon-codes', {
                method: 'POST',
                body: {
                    ...(code ? { code } : {}),
                    months_free_base: Number.isFinite(months) ? Math.max(1, Math.min(60, Math.round(months))) : 1,
                },
            });
            state.adminCoupons = await api('/admin/coupon-codes');
            state.notice = 'Coupon code created.';
        } catch (error) {
            state.error = friendlyError(error, 'create the coupon code');
        } finally {
            state.adminLoading = false;
            render();
        }
    }

    async function deleteAdminCouponCode(id) {
        if (!id || state.adminLoading) return;
        if (!confirm('Delete this coupon code? The code can no longer be redeemed.')) return;
        state.adminLoading = true;
        state.error = '';
        render();
        try {
            await api(`/admin/coupon-codes/${encodeURIComponent(id)}`, { method: 'DELETE' });
            state.adminCoupons = await api('/admin/coupon-codes');
            state.notice = 'Coupon code deleted.';
        } catch (error) {
            state.error = friendlyError(error, 'delete the coupon code');
        } finally {
            state.adminLoading = false;
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
            limits: readAdminLimits(form),
            notes: String(formData.get('notes') || '').trim() || null,
        };
        state.adminLoading = true;
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
            state.adminLoading = false;
            render();
        }
    }

    async function deleteEnterpriseLimits(id) {
        if (!id || !window.confirm('Remove this enterprise customer override?')) return;
        state.adminLoading = true;
        state.error = '';
        render();
        try {
            await api(`/admin/plan-limits/enterprise-customers/${encodeURIComponent(id)}`, { method: 'DELETE' });
            state.adminPlanLimits = await api('/admin/plan-limits');
            state.notice = 'Enterprise override removed.';
        } catch (error) {
            state.error = friendlyError(error, 'remove enterprise limits');
        } finally {
            state.adminLoading = false;
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
            note_limit: nullableNumber(container.querySelector('input[name="note_limit"]')?.value),
            recurring_tasks_enabled: checked('recurring_tasks_enabled'),
            recurring_reminders_enabled: checked('recurring_reminders_enabled'),
            recurring_calendar_enabled: checked('recurring_calendar_enabled'),
            email_reminders_enabled: checked('email_reminders_enabled'),
            notes_enabled: checked('notes_enabled'),
        };
    }

    function nullableNumber(value) {
        const normalized = String(value ?? '').trim();
        if (!normalized) return null;
        const parsed = Number.parseFloat(normalized);
        return Number.isFinite(parsed) ? parsed : null;
    }

    function itemSaveRequest(kind, item, data, form) {
        const color = data.color || themeAccentColor();
        if (kind === 'task') {
            const workspaceId = selectedPrimaryWorkspaceId(form, item);
            const syncTo = selectedSyncWorkspaceIds(form, workspaceId);
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
                    ...metadataWithoutRecurrence(existingMetadata),
                    ...(parentTaskId ? { parent_task_id: Number(parentTaskId) } : {}),
                    recurrence: recurrence.value,
                    ...recurrence.details,
                },
                sync_to_workspace_ids: syncTo,
            };
            if (!item && workspaceId) body.workspace_id = Number(workspaceId);
            return {
                body,
                path: item ? `/tasks/${item.id}` : '/tasks',
                options: { method: item ? 'PATCH' : 'POST', body },
            };
        } else if (kind === 'reminder') {
            const workspaceId = selectedPrimaryWorkspaceId(form, item);
            const syncTo = selectedSyncWorkspaceIds(form, workspaceId);
            const existingMetadata = typeof item?.metadata === 'object' && item?.metadata ? item.metadata : {};
            const recurrence = recurrenceFormData(form, data);
            const recipientsByWorkspace = selectedReminderRecipientsByWorkspace(form);
            const recipientUserIds = uniqueReminderRecipientUserIds(recipientsByWorkspace);
            const body = {
                title: data.title,
                remind_at: fromDatetimeLocal(data.time),
                status: item?.status || 'scheduled',
                category: data.category || null,
                color,
                metadata: {
                    ...metadataWithoutRecurrence(existingMetadata),
                    recurrence: recurrence.value,
                    ...recurrence.details,
                    notification_recipients_by_workspace: recipientsByWorkspace,
                    notification_recipient_user_ids: recipientUserIds,
                },
                sync_to_workspace_ids: syncTo,
            };
            if (!item && workspaceId) body.workspace_id = Number(workspaceId);
            return {
                body,
                path: item ? `/reminders/${item.id}` : '/reminders',
                options: { method: item ? 'PATCH' : 'POST', body },
            };
        } else if (kind === 'event') {
            const workspaceId = selectedPrimaryWorkspaceId(form, item);
            const syncTo = selectedSyncWorkspaceIds(form, workspaceId);
            const allDay = form.elements.allDay?.checked || false;
            const existingMetadata = typeof item?.metadata === 'object' && item?.metadata ? item.metadata : {};
            const generatedOccurrence = eventIsGeneratedOccurrence(item);
            const recurrence = generatedOccurrence ? { value: null, details: {} } : recurrenceFormData(form, data);
            const metadata = {
                ...metadataWithoutRecurrence(existingMetadata),
                ...recurrence.details,
                ...eventPlaceMetadataFromFormData(data),
            };
            delete metadata.all_day;
            if (generatedOccurrence) {
                delete metadata.days;
                delete metadata.interval;
                delete metadata.unit;
            }
            const originalStartsAt = item?.starts_at || item?.startsAt || '';
            const originalEndsAt = item?.ends_at || item?.endsAt || '';
            const preserveLiteralAllDayBounds = Boolean(item && eventAllDay(item) && allDay);
            const startsAt = allDay
                ? (preserveLiteralAllDayBounds && data.allDayStart === dateOnly(originalStartsAt)
                    ? originalStartsAt
                    : fromDateInputStart(data.allDayStart))
                : fromDatetimeLocal(data.time);
            const endsAt = allDay
                ? (preserveLiteralAllDayBounds && originalEndsAt && data.allDayEnd === dateOnly(originalEndsAt)
                    ? originalEndsAt
                    : fromDateInputEndExclusive(data.allDayEnd))
                : fromDatetimeLocal(data.endsAt);
            const body = {
                title: data.title,
                description: data.description || null,
                location: data.location || null,
                starts_at: startsAt,
                ends_at: endsAt,
                category: data.category || null,
                color,
                is_critical: form.elements.critical?.checked || false,
                recurrence: recurrence.value,
                status: data.status || 'scheduled',
                all_day: allDay,
                sync_to_workspace_ids: syncTo,
                metadata,
            };
            if (!item && workspaceId) body.workspace_id = Number(workspaceId);
            return {
                body,
                eventReminderMinutesBefore: form.elements.createEventReminder?.checked ? Number(data.eventReminderMinutesBefore || 15) : null,
                path: item ? `/calendar-events/${item.id}` : '/calendar-events',
                options: { method: item ? 'PATCH' : 'POST', body },
            };
        }
        return { body: {}, path: '', options: {} };
    }

    function eventPlaceMetadataFromFormData(data = {}) {
        const location = String(data.location || '').trim();
        const address = String(data.placeFormattedAddress || '').trim();
        if (!location || !address || location !== address) {
            return {
                place_id: null,
                place_formatted_address: null,
                place_lat: null,
                place_lng: null,
                google_maps_uri: null,
            };
        }
        return {
            place_id: String(data.placeId || '').trim() || null,
            place_formatted_address: address,
            place_lat: nullableNumber(data.placeLat),
            place_lng: nullableNumber(data.placeLng),
            google_maps_uri: String(data.googleMapsUri || '').trim() || null,
        };
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
                    createLinkedEventReminderIfRequested(saved, request).catch((error) => {
                        state.error = friendlyError(error, 'create the event reminder');
                        render();
                    });
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

    async function createLinkedEventReminderIfRequested(event, request = {}) {
        const minutesBefore = Number(request.eventReminderMinutesBefore);
        if (!event?.id || !Number.isFinite(minutesBefore) || minutesBefore < 0) return null;
        const body = request.body || {};
        const startsAt = event.starts_at || event.startsAt || body.starts_at;
        const start = startsAt ? new Date(startsAt) : null;
        if (!start || Number.isNaN(start.getTime())) return null;
        const remindAt = new Date(start);
        remindAt.setMinutes(remindAt.getMinutes() - minutesBefore);
        const recurrence = recurrenceOptions().includes(body.recurrence)
            ? body.recurrence
            : recurrenceOptions().includes(event.recurrence) ? event.recurrence : 'none';
        const metadata = {
            source: 'event_reminder',
            minutes_before: minutesBefore,
            recurrence,
            ...(body.metadata || {}),
            event_reminder: {
                minutes_before: minutesBefore,
                follows_event_recurrence: recurrence && recurrence !== 'none',
            },
        };
        const workspaceId = event.workspace_id || event.workspaceId || body.workspace_id || currentWorkspaceId() || null;
        const reminder = await api(workspaceScopedPath('/reminders', workspaceId), {
            method: 'POST',
            body: {
                calendar_event_id: event.id,
                title: `Reminder: ${event.title || body.title || 'Event'}`,
                remind_at: remindAt.toISOString(),
                category: event.category || body.category || null,
                color: event.color || body.color || themeAccentColor(),
                metadata,
                ...(workspaceId ? { workspace_id: Number(workspaceId) } : {}),
            },
        });
        cacheSavedItem('reminder', reminder);
        return reminder;
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
                status: body.status || item?.status || 'scheduled',
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
                status: body.status || item?.status || 'scheduled',
                is_critical: body.is_critical === true,
                isCritical: body.is_critical === true,
                all_day: body.all_day === true,
                allDay: body.all_day === true,
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
        const recurrence = recurrenceOptions().includes(data.recurrence) ? data.recurrence : 'none';
        const details = {};
        if (recurrence === 'specific_days') {
            details.days = Array.from(form.querySelectorAll('input[name="days"]:checked'))
                .map((input) => input.value)
                .filter((day) => ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'].includes(day));
        }
        if (recurrence === 'interval') {
            const interval = Number(data.interval);
            details.interval = Number.isInteger(interval) && interval > 0 ? interval : 1;
            details.unit = ['days', 'weeks', 'months', 'years'].includes(data.unit) ? data.unit : 'days';
        }
        return {
            value: recurrence,
            details,
        };
    }

    function metadataWithoutRecurrence(metadata = {}) {
        const canonical = { ...(metadata && typeof metadata === 'object' ? metadata : {}) };
        delete canonical.recurrence;
        delete canonical.days;
        delete canonical.interval;
        delete canonical.unit;
        return canonical;
    }

    function selectedWorkspaceAssignmentIds(form) {
        return Array.from(form?.querySelectorAll('input[name="workspaceAssignmentIds"]:checked') || [])
            .map((input) => Number(input.value))
            .filter(Boolean);
    }

    function selectedPrimaryWorkspaceId(form, item = null) {
        const selected = selectedWorkspaceAssignmentIds(form).map(String);
        const savedWorkspaceId = String(item?.workspace_id || item?.workspaceId || '');
        if (savedWorkspaceId && selected.includes(savedWorkspaceId)) return savedWorkspaceId;
        const personalId = String(personalWorkspaceId() || '');
        if (personalId && selected.includes(personalId)) return personalId;
        return selected[0] || personalId || savedWorkspaceId || '';
    }

    function selectedSyncWorkspaceIds(form, primaryWorkspaceId = selectedPrimaryWorkspaceId(form, state.modal?.item)) {
        return selectedWorkspaceAssignmentIds(form)
            .filter((workspaceId) => String(workspaceId) !== String(primaryWorkspaceId || ''));
    }

    function selectedReminderRecipientsByWorkspace(form) {
        const map = {};
        form?.querySelectorAll('input[name="notificationRecipients"]:checked').forEach((input) => {
            const workspaceId = String(input.dataset.recipientWorkspaceId || '');
            const userId = Number(input.value);
            if (!workspaceId || !userId) return;
            map[workspaceId] ??= [];
            map[workspaceId].push(userId);
        });
        Object.keys(map).forEach((workspaceId) => {
            map[workspaceId] = Array.from(new Set(map[workspaceId]));
        });
        return map;
    }

    function uniqueReminderRecipientUserIds(recipientsByWorkspace = {}) {
        return Array.from(new Set(Object.values(recipientsByWorkspace).flat().map(Number).filter(Boolean)));
    }

    function syncSelectedCategoryColor(event) {
        const select = event.currentTarget;
        const option = event.currentTarget.selectedOptions?.[0];
        const color = option?.dataset?.categoryColor || themeAccentColor();
        const form = select.closest('form');
        const input = form?.querySelector('input[name="color"]');
        const colorField = form?.querySelector('[data-no-category-color-field]');
        if (!input) return;
        if (select.value) {
            input.dataset.noCategoryColor = input.dataset.noCategoryColor || safeColor(input.value || themeAccentColor());
            input.value = safeColor(color);
            if (colorField) colorField.hidden = true;
            return;
        }
        input.value = safeColor(input.dataset.noCategoryColor || themeAccentColor());
        if (colorField) colorField.hidden = false;
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
            || eventIsGeneratedOccurrence(event);
    }

    function eventIsGeneratedOccurrence(event = null) {
        const metadata = typeof event?.metadata === 'object' && event?.metadata ? event.metadata : {};
        return metadata.recurrence_generated === true
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
            const endInput = form.querySelector('input[name="endsAt"]');
            const allDayStart = form.querySelector('input[name="allDayStart"]');
            const allDayEnd = form.querySelector('input[name="allDayEnd"]');
            if (startInput?.value && allDayStart) {
                setDateTimePickerValue(allDayStart, dateOnly(startInput.value), {
                    dispatch: false,
                });
            }
            if (allDayEnd) {
                const startDate = parseLocalDate(startInput?.value || new Date());
                const endDate = parseLocalDate(endInput?.value || startDate);
                const boundary = dateOnly(endDate) > dateOnly(startDate) ? endDate : addDays(startDate, 1);
                setDateTimePickerValue(allDayEnd, dateOnly(boundary), {
                    dispatch: false,
                });
            }
        } else {
            const startInput = form.querySelector('input[name="time"]');
            const endInput = form.querySelector('input[name="endsAt"]');
            const allDayStart = form.querySelector('input[name="allDayStart"]');
            const allDayEnd = form.querySelector('input[name="allDayEnd"]');
            if (allDayStart?.value && startInput && endInput && !startInput.value) {
                const start = parseLocalDate(allDayStart.value);
                const end = parseLocalDate(allDayEnd?.value || allDayStart.value);
                start.setHours(9, 0, 0, 0);
                end.setHours(10, 0, 0, 0);
                setDateTimePickerValue(startInput, toDatetimeLocal(start), {
                    dispatch: false,
                });
                setDateTimePickerValue(endInput, toDatetimeLocal(end > start ? end : defaultEventEnd(start)), {
                    dispatch: false,
                });
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
                field.required = enabled;
            }
        });
        group.querySelectorAll('button').forEach((button) => {
            button.disabled = !enabled;
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
                status: completed ? 'open' : 'completed',
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
                    status: completed ? 'open' : 'completed',
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
        const optimistic = { ...reminder, status: completed ? 'scheduled' : 'completed' };
        state.pendingReminderUpserts.set(String(reminder.id), optimistic);
        state.reminders = upsertById(state.reminders, optimistic);
        state.error = '';
        saveDashboardCache();
        render();
        try {
            const saved = await api(`/reminders/${reminder.id}`, {
                method: 'PATCH',
                body: { status: completed ? 'scheduled' : 'completed' },
            });
            cacheSavedItem('reminder', saved);
            refreshOnlyInBackground({ skipCalendarSync: true });
        } catch (error) {
            restoreSnapshot('reminder', snapshot);
            state.error = friendlyError(error, completed ? 'reopen that reminder' : 'complete that reminder');
            render();
        }
    }

    async function refreshOnly(shouldRender = true, options = {}) {
        const generation = ++dashboardRefreshGeneration;
        try {
            const calendarPath = options.skipCalendarSync === false ? '/calendar-events' : '/calendar-events?skip_google_sync=1&skip_outlook_sync=1';
            const workspaceId = currentWorkspaceId();
            const [summary, tasks, pastTasks, reminders, calendar, noteFolders, notes, categories, googleStatus, outlookStatus] = await Promise.all([
                api(workspaceScopedPath('/today', workspaceId)),
                api(workspaceScopedPath('/tasks', workspaceId)),
                api(workspaceScopedPath('/tasks/past', workspaceId)),
                api(workspaceScopedPath('/reminders', workspaceId)),
                api(workspaceScopedPath(calendarPath, workspaceId)),
                api(workspaceScopedPath('/note-folders', workspaceId)),
                api(workspaceScopedPath('/notes', workspaceId)),
                api(workspaceScopedPath('/event-categories', workspaceId)),
                api('/google-calendar/status?cached=1').catch(() => state.googleStatus),
                api('/outlook-calendar/status?cached=1').catch(() => state.outlookStatus),
            ]);
            if (generation !== dashboardRefreshGeneration) return;
            state.summary = summary;
            state.tasks = reconcileTaskRefresh(mergeById(normalizeList(tasks.length ? tasks : summary?.tasks), normalizeList(pastTasks)));
            state.reminders = reconcileReminderRefresh(reminders.length ? reminders : summary?.reminders);
            state.calendar = reconcileCalendarRefresh(calendar.length ? calendar : summary?.calendar_events);
            state.noteFolders = normalizeNoteFolders(noteFolders);
            state.notes = normalizeNotes(notes);
            ensureSelectedNote();
            state.categories = normalizeList(categories);
            state.googleStatus = googleStatus;
            state.outlookStatus = outlookStatus;
            state.googleStatus = googleStatus;
            state.user = mergeUser(state.user, summary?.user, summary);
            setActiveWorkspaceLocally(workspaceId, { persist: false });
            saveDashboardCache();
            if (shouldRender) renderDashboardDataUpdate({ deferIfEditing: options.deferRender === true });
        } catch (error) {
            if (generation !== dashboardRefreshGeneration) return;
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

    function startBeanEventFeed() {
        if (!state.token || state.phase !== 'signedIn' || beanEventAbort) return;
        beanEventStatusStartedAt = Date.now();
        setBeanIdleStatus();
        beanEventAbort = new AbortController();
        pollBeanEvents(beanEventAbort.signal);
    }

    function stopBeanEventFeed() {
        if (beanEventAbort) {
            beanEventAbort.abort();
            beanEventAbort = null;
        }
    }

    async function pollBeanEvents(signal) {
        while (!signal.aborted && state.token && state.phase === 'signedIn') {
            try {
                const response = await fetch(`/api/bean/events?after=${encodeURIComponent(beanEventLastId)}&wait=25`, {
                    headers: { Accept: 'text/event-stream', Authorization: `Bearer ${state.token}` },
                    signal,
                });
                if (response.status === 401) return;
                if (!response.ok || !response.body) throw new Error('Bean event stream failed.');
                const reader = response.body.getReader();
                const decoder = new TextDecoder();
                let buffer = '';
                while (!signal.aborted) {
                    const { value, done } = await reader.read();
                    if (done) break;
                    buffer += decoder.decode(value, { stream: true });
                    const parts = buffer.split('\n\n');
                    buffer = parts.pop() || '';
                    parts.forEach(handleBeanEventBlock);
                }
            } catch (error) {
                if (signal.aborted) return;
                await sleep(2500);
            }
        }
        if (beanEventAbort?.signal === signal) beanEventAbort = null;
    }

    function handleBeanEventBlock(block) {
        const lines = String(block || '').split('\n');
        const idLine = lines.find((line) => line.startsWith('id:'));
        const dataLine = lines.find((line) => line.startsWith('data:'));
        if (idLine) beanEventLastId = Math.max(beanEventLastId, Number(idLine.slice(3).trim()) || 0);
        if (!dataLine) return;
        let event;
        try { event = JSON.parse(dataLine.slice(5).trim()); } catch (_) { return; }
        if (!event?.id) return;
        if (!normalizeList(state.bean.activity).some((item) => Number(item.id) === Number(event.id))) {
            state.bean.activity = [...normalizeList(state.bean.activity), event].slice(-100);
        }
        const payloadMode = event.payload?.mode || '';
        const liveStatusEvent = isLiveBeanStatusEvent(event);
        if (event.type === 'assistant_message' && liveStatusEvent && resolvePendingBeanVoiceResponseFromActivity(event)) {
            render();
            return;
        }
        const voiceOwnsIdleStatus = state.bean.voiceActive && ['thinking', 'speaking', 'listening'].includes(state.bean.mode) && (payloadMode === 'wake_listening' || payloadMode === 'privacy' || event.label === 'Done');
        if (event.type === 'status' && payloadMode && liveStatusEvent && !voiceOwnsIdleStatus) {
            if (payloadMode === 'wake_listening' || payloadMode === 'privacy' || event.label === 'Done') {
                setBeanIdleStatus();
            } else {
                state.bean.mode = payloadMode;
                state.bean.statusText = beanEventStatusText(event, state.bean.statusText);
            }
        } else if (event.type === 'tool_started' && liveStatusEvent) {
            state.bean.mode = 'working';
            state.bean.statusText = beanEventStatusText(event, 'Working…');
        } else if ((event.type === 'tool_completed' || event.type === 'tool_failed') && liveStatusEvent) {
            state.bean.mode = event.type === 'tool_failed' ? 'error' : 'working';
            state.bean.statusText = beanEventStatusText(event, event.type === 'tool_failed' ? 'Action failed' : 'Action complete');
        } else if (event.type === 'assistant_message' && liveStatusEvent) {
            setBeanIdleStatus();
        }
        if (state.bean.panelOpen || ['status', 'tool_started', 'tool_completed', 'tool_failed', 'assistant_message'].includes(event.type)) {
            render();
        }
    }

    function isLiveBeanStatusEvent(event) {
        const createdAt = Date.parse(String(event?.created_at || ''));
        return !createdAt || createdAt >= beanEventStatusStartedAt;
    }

    function resolvePendingBeanVoiceResponseFromActivity(event) {
        if (!beanPendingVoiceResponse || beanPendingVoiceResponse.resolved || !state.bean.voiceActive) return false;
        const createdAt = Date.parse(String(event?.created_at || '')) || Date.now();
        if (createdAt + 1000 < beanPendingVoiceResponse.startedAt) return false;
        const answer = String(event?.label || '').trim();
        if (!answer) return false;
        beanPendingVoiceResponse.resolved = true;
        window.clearTimeout(beanPendingVoiceResponseTimer);
        beanPendingVoiceResponseTimer = 0;
        markBeanVoiceActivity();
        state.bean.busy = false;
        state.bean.error = '';
        state.bean.voiceTranscript = '';
        state.bean.messages = [...normalizeList(state.bean.messages), { role: 'assistant', content: answer }];
        logBeanVoiceLifecycleEvent('bean_response_received', { run_id: event.bean_run_id || event.beanRunId || null, label: answer.slice(0, 160), recovered_from_activity: true });
        state.bean.mode = 'listening';
        state.bean.statusText = 'Listening — speak to Bean';
        return true;
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
                scheduleDashboardLiveRefresh(changes);
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

    function scheduleDashboardLiveRefresh(changes = [], options = {}) {
        window.clearTimeout(dashboardRefreshTimer);
        const delay = options.immediate ? 0 : (changes.length ? 350 : 100);
        dashboardRefreshTimer = window.setTimeout(() => {
            dashboardRefreshTimer = 0;
            if (state.phase !== 'signedIn') return;
            if (options.forceRender) {
                refreshOnly(true, { skipCalendarSync: true, deferRender: false }).catch(() => {});
                return;
            }
            refreshOnlyInBackground({ skipCalendarSync: true });
        }, delay);
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
        api(workspaceScopedPath('/calendar-events?skip_google_sync=1&skip_outlook_sync=1'))
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

    async function refreshCalendar() {
        if (state.calendarRefreshing) return;
        state.calendarRefreshing = true;
        state.error = '';
        render();
        try {
            const [calendar, googleStatus, outlookStatus] = await Promise.all([
                api(workspaceScopedPath('/calendar-events')),
                api('/google-calendar/status').catch(() => state.googleStatus),
                api('/outlook-calendar/status').catch(() => state.outlookStatus),
            ]);
            state.calendar = reconcileCalendarRefresh(calendar);
            state.googleStatus = googleStatus;
            state.outlookStatus = outlookStatus;
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
            await loadAdminData(true);
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
        if (allowed.blocked) {
            showCalendarHistoryLimit();
        } else {
            clearPlanLimitError();
        }
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

    async function updateThemeModePreference(event) {
        const themeMode = normalizeThemeModeKey(event.currentTarget.value);
        if (themeMode === currentThemeModeKey()) return;
        const previousUser = state.user;
        state.user = { ...(state.user || {}), theme_mode: themeMode };
        state.error = '';
        state.notice = '';
        applyAppTheme();
        render();
        try {
            state.user = await api('/auth/me', { method: 'PATCH', body: { theme_mode: themeMode } });
            state.notice = 'Theme mode saved.';
            applyAppTheme();
            render();
        } catch (error) {
            state.user = previousUser;
            applyAppTheme();
            state.error = friendlyError(error, 'save theme mode');
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
            state.billingPlanInterval = normalizedBillingInterval(subscription?.billing_interval || subscription?.billingInterval || state.billingPlanInterval);
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
        const billingInterval = normalizedBillingInterval(mount.querySelector('[data-billing-interval-select]')?.value || state.billingPlanInterval);
        if (!subscriptionPlans[plan]) return;
        const currentPlan = String(state.subscriptionSummary?.tier || state.user?.subscription_tier || state.user?.subscriptionTier || 'base').toLowerCase();
        const currentInterval = normalizedBillingInterval(state.subscriptionSummary?.billing_interval || state.subscriptionSummary?.billingInterval || state.billingPlanInterval);
        if (plan === currentPlan && billingInterval === currentInterval) {
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
                body: { plan, billing_interval: billingInterval },
            });
            if (result?.url) {
                window.location.href = result.url;
                return;
            }
            state.subscriptionSummary = result?.subscription || state.subscriptionSummary;
            state.billingPlanInterval = normalizedBillingInterval(result?.billing_interval || result?.billingInterval || state.subscriptionSummary?.billing_interval || state.subscriptionSummary?.billingInterval || billingInterval);
            const freshUser = await api('/auth/me').catch(() => null);
            if (freshUser) state.user = freshUser;
            state.billingMessage = `Plan changed to ${subscriptionPlans[plan].label} ${billingInterval === 'yearly' ? 'yearly' : 'monthly'}.`;
        } catch (error) {
            state.billingError = friendlyError(error, 'change your subscription');
        } finally {
            state.billingBusy = false;
            render();
        }
    }

    async function redeemCouponCodeFromInput(context = 'billing') {
        if (state.busy || state.billingBusy) return;
        const input = mount.querySelector(context === 'subscribe' ? '[data-subscribe-coupon-code]' : '[data-billing-coupon-code]');
        const code = String(input?.value || '').replace(/\D/g, '').slice(0, 6);
        if (code.length !== 6) {
            if (context === 'subscribe') {
                state.error = 'Enter a 6-digit coupon code.';
            } else {
                state.billingError = 'Enter a 6-digit coupon code.';
                state.billingMessage = '';
            }
            render();
            return;
        }
        if (context === 'subscribe') {
            state.busy = true;
            state.error = '';
        } else {
            state.billingBusy = true;
            state.billingError = '';
            state.billingMessage = 'Applying coupon...';
            state.billingCouponCode = code;
        }
        render();
        try {
            const result = await api('/billing/coupon-codes/redeem', {
                method: 'POST',
                body: { code },
            });
            state.subscriptionSummary = result?.subscription || state.subscriptionSummary;
            const freshUser = await api('/auth/me').catch(() => null);
            if (freshUser) state.user = freshUser;
            if (context === 'subscribe') {
                state.busy = false;
                state.notice = couponAppliedMessage(result?.subscription);
                history.pushState({}, '', '/app');
                await loadSignedIn();
                return;
            }
            state.billingCouponCode = '';
            state.billingMessage = couponAppliedMessage(result?.subscription);
        } catch (error) {
            if (context === 'subscribe') {
                state.error = friendlyError(error, 'apply your coupon code');
            } else {
                state.billingError = friendlyError(error, 'apply your coupon code');
                state.billingMessage = '';
            }
        } finally {
            state.busy = false;
            state.billingBusy = false;
            render();
        }
    }

    function couponAppliedMessage(subscription = null) {
        const expiresAt = subscription?.base_comp_expires_at || subscription?.baseCompExpiresAt;
        return expiresAt
            ? `Coupon applied. Free Base access runs through ${formatDateOnly(expiresAt)}.`
            : 'Coupon applied. Free Base access is active.';
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
        const switchGeneration = ++workspaceSwitchGeneration;
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
            if (switchGeneration !== workspaceSwitchGeneration || String(currentWorkspaceId()) !== String(id)) return;
            state.notice = `Switched to ${workspaceDisplayName(workspace)}.`;
            renderDashboardDataUpdate({ deferIfEditing: true });
        } catch (error) {
            if (switchGeneration !== workspaceSwitchGeneration) return;
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

    async function connectExternalCalendar(provider) {
        state.modal = null;
        await externalCalendarAction(`${provider}:connect`);
    }

    async function externalCalendarAction(actionKey) {
        const [provider, action] = String(actionKey || '').split(':');
        const isOutlook = provider === 'outlook';
        const label = isOutlook ? 'Microsoft Outlook' : 'Google Calendar';
        const authUrlKey = isOutlook ? 'outlookAuthUrl' : 'googleAuthUrl';
        const statusKey = isOutlook ? 'outlookStatus' : 'googleStatus';
        const basePath = isOutlook ? '/outlook-calendar' : '/google-calendar';
        try {
            if (action === 'connect') {
                const result = await api(`${basePath}/auth-url`, { method: 'POST' });
                state[authUrlKey] = result.auth_url;
                window.open(result.auth_url, '_blank', 'noopener,noreferrer');
                state.notice = `Finish approving ${label} access in the browser, then tap Check connection.`;
            } else if (action === 'copy') {
                await navigator.clipboard.writeText(state[authUrlKey]);
                state.notice = `${label} authorization link copied.`;
            } else if (action === 'check' || action === 'sync') {
                const result = await api(`${basePath}/sync`, { method: 'POST' });
                state[statusKey] = result.status;
                state.notice = `${label} sync pulled ${result.imported || 0} external event${(result.imported || 0) === 1 ? '' : 's'} into your calendar. Local events stay local.`;
            } else if (action === 'disconnect') {
                state[statusKey] = await api(basePath, { method: 'DELETE' });
                state.notice = `${label} sync disconnected.`;
            }
            render();
        } catch (error) {
            state.error = friendlyError(error, `update ${label} sync`);
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

    async function updateOutlookCalendarSelection() {
        const selected = Array.from(mount.querySelectorAll('[data-outlook-calendar]:checked')).map((input) => input.value);
        try {
            state.outlookStatus = await api('/outlook-calendar/calendars', {
                method: 'PATCH',
                body: { selected_calendar_ids: selected, default_calendar_id: selected[0] || null },
            });
            state.notice = 'Outlook calendar choices saved.';
            render();
        } catch (error) {
            state.error = friendlyError(error, 'save Outlook calendar choices');
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
        stopBeanEventFeed();
        stopBeanWakeListening();
        stopBeanVoiceSession();
        clearToken();
        state.phase = 'signedOut';
        state.authMode = 'login';
        state.user = null;
        state.summary = null;
        history.pushState({}, '', '/login');
        render();
    }

    async function deleteAccount() {
        if (!confirm('Delete your HeyBean account and data? This cannot be undone.')) return;
        try {
            await api('/account', { method: 'DELETE' });
            stopDashboardChangeFeed();
            stopBeanEventFeed();
            stopBeanWakeListening();
            stopBeanVoiceSession();
            clearToken();
            state.phase = 'signedOut';
            state.authMode = 'login';
            state.user = null;
            state.summary = null;
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
        return state.tasks.filter((task) => task?.status === 'open');
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

    function scheduledReminders() {
        return state.reminders.filter((reminder) => reminder?.status === 'scheduled');
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
        return scheduledReminders()
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
        const overdue = itemOverdue(task, 'task');
        const dueLabel = task.due_at || task.dueAt ? formatDueTime(task.due_at || task.dueAt, { includeDate: overdue }) : '';
        if (overdue) parts.push('overdue');
        if (dueLabel) parts.push(`Due ${dueLabel}`);
        if (taskIsRecurring(task)) parts.push(recurrenceSummary(task));
        return parts.join(' · ');
    }

    function criticalReminderSubtitle(reminder) {
        const parts = [];
        const overdue = itemOverdue(reminder, 'reminder');
        const dateLabel = reminderDateValue(reminder) ? formatDueTime(reminderDateValue(reminder), { includeDate: overdue }) : '';
        if (overdue) parts.push('overdue');
        if (dateLabel) parts.push(dateLabel);
        if (itemIsRecurring(reminder)) parts.push(recurrenceSummary(reminder));
        return parts.join(' · ') || 'No reminder time';
    }

    function criticalEventSubtitle(event) {
        const parts = [];
        if (event.starts_at || event.startsAt || event.ends_at || event.endsAt) parts.push(eventTime(event));
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
            const interval = Number.parseInt(metadata.interval, 10);
            if (!Number.isInteger(interval) || interval <= 0) return null;
            if (metadata.unit === 'days') return addDays(date, interval);
            if (metadata.unit === 'weeks') return addDays(date, interval * 7);
            if (metadata.unit === 'months') return addMonthsNoOverflow(date, interval);
            if (metadata.unit === 'years') return addYearsNoOverflow(date, interval);
            return null;
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
        const unit = recurrence?.unit;
        if (!['days', 'weeks', 'months', 'years'].includes(unit)) return 'Custom interval';
        return `Every ${interval} ${intervalUnitLabel(unit, interval)}`;
    }

    function intervalUnitLabel(unit, interval) {
        const normalized = { days: 'day', weeks: 'week', months: 'month', years: 'year' }[unit] || '';
        return interval === 1 ? normalized : `${normalized}s`;
    }

    function workspaces() {
        return normalizeList(state.user?.workspaces || state.summary?.workspaces);
    }

    function currentWorkspaceId() {
        return state.user?.active_workspace?.id || state.user?.activeWorkspace?.id || state.summary?.workspace?.id || state.summary?.workspaceId || workspaces().find((workspace) => workspace.active || workspace.is_default || workspace.isDefault)?.id || workspaces()[0]?.id || '';
    }

    function personalWorkspaceId() {
        return workspaces().find((workspace) => workspace.type === 'personal' || workspace.kind === 'personal' || workspace.is_personal || workspace.isPersonal)?.id || '';
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

    function taskCompleted(task) {
        return task?.status === 'completed';
    }

    function reminderCompleted(reminder) {
        return reminder?.status === 'completed';
    }

    function taskSubtitle(task) {
        const dueValue = task.due_at || task.dueAt || '';
        return [
            dueValue ? formatDueTime(dueValue, { includeDate: itemOverdue(task, 'task') }) : '',
            recurrenceSummary(task),
        ].filter(Boolean).join(' · ');
    }

    function reminderSubtitle(reminder) {
        const bits = [];
        const dueValue = reminder.remind_at || reminder.due_at || reminder.dueAt || '';
        if (dueValue) bits.push(formatDueTime(dueValue, { includeDate: itemOverdue(reminder, 'reminder') }));
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
        return end ? formatCompactTimeRange(start, end) : formatCompactMeridiemTime(start);
    }

    function commandCenterEventTime(event) {
        return eventTime(event);
    }

    function eventStartTime(event) {
        if (eventAllDay(event)) return 'All day';
        const start = event.starts_at || event.startsAt;
        return start ? formatTime(start) : 'All day';
    }

    function monthEventStartTime(event) {
        if (eventAllDay(event)) return 'All day';
        const start = event.starts_at || event.startsAt;
        return start ? formatCompactMeridiemTime(start) : 'All day';
    }

    function eventEndTime(event) {
        if (eventAllDay(event)) return 'All day';
        const end = event.ends_at || event.endsAt;
        return end ? formatTime(end) : '';
    }

    function monthEventEndTime(event) {
        if (eventAllDay(event)) return 'All day';
        const end = event.ends_at || event.endsAt;
        return end ? formatCompactMeridiemTime(end) : '';
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
        if (dateOnly(event.starts_at || event.startsAt) === dayValue) {
            return options.compact ? monthEventStartTime(event) : eventStartTime(event);
        }
        if (dateOnly(event.ends_at || event.endsAt) === dayValue) {
            if (options.showEndTime === false) return '';
            return options.compact ? monthEventEndTime(event) : eventEndTime(event);
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
        return 64;
    }

    function timelineGutterWidth() {
        return window.matchMedia?.('(max-width: 700px)').matches ? 56 : 74;
    }

    function eventAllDay(event = null) {
        const metadata = typeof event?.metadata === 'object' && event?.metadata ? event.metadata : {};
        return (event?.all_day ?? metadata.all_day) === true;
    }

    function allDayEventStartDate(event = {}) {
        const metadata = eventMetadata(event);
        return String(metadata.all_day_start_date || metadata.allDayStartDate || event.starts_at || event.startsAt || '').slice(0, 10);
    }

    function allDayEventExclusiveEndDate(event = {}) {
        const metadata = eventMetadata(event);
        const explicitEnd = String(metadata.all_day_exclusive_end_date || metadata.allDayExclusiveEndDate || event.ends_at || event.endsAt || '').slice(0, 10);
        const start = allDayEventStartDate(event);
        if (explicitEnd && explicitEnd > start) return explicitEnd;
        if (!start) return '';
        return dateOnly(addDays(parseLocalDate(start), 1));
    }

    function eventIntersectsDay(event, day) {
        const startValue = event.starts_at || event.startsAt;
        if (!startValue) return false;
        const dayStart = new Date(parseLocalDate(day));
        dayStart.setHours(0, 0, 0, 0);
        const dayEnd = addDays(dayStart, 1);
        if (eventAllDay(event)) {
            const dayValue = dateOnly(dayStart);
            const startDate = allDayEventStartDate(event);
            const exclusiveEndDate = allDayEventExclusiveEndDate(event);
            return Boolean(startDate && exclusiveEndDate && dayValue >= startDate && dayValue < exclusiveEndDate);
        }
        const start = new Date(startValue);
        const endValue = event.ends_at || event.endsAt;
        const end = endValue
            ? new Date(endValue)
            : addMinutes(start, 60);
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

    function notesEnabled() {
        if (state.onboardingTourActive) return true;
        if (userIsAdmin()) return true;
        const limits = currentPlanLimits();
        const noteLimit = limits.note_limit ?? limits.noteLimit;
        return Boolean(limits.notes_enabled ?? limits.notesEnabled) || noteLimit === null || Number(noteLimit) > 0;
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

    function formatDueTime(value, options = {}) {
        if (!value) return '';
        const includeDate = Boolean(options.includeDate);
        if (wireValueLooksDateOnly(value)) {
            const date = parseLocalDate(value);
            if (Number.isNaN(date.getTime())) return String(value).trim();
            return relativeDueDateLabel(date);
        }
        if (includeDate) {
            const date = parseLocalDate(value);
            if (!Number.isNaN(date.getTime())) {
                const time = formatCompactMeridiemTime(value);
                if (time) return `${relativeDueDateLabel(date)} ${time}`;
            }
        }
        return formatCompactMeridiemTime(value) || String(value).trim();
    }

    function relativeDueDateLabel(value) {
        const date = parseLocalDate(value);
        if (Number.isNaN(date.getTime())) return String(value || '').trim();
        const today = parseLocalDate(dateOnly(new Date()));
        const yesterday = addDays(today, -1);
        const tomorrow = addDays(today, 1);
        if (sameDate(date, today)) return 'Today';
        if (sameDate(date, yesterday)) return 'Yesterday';
        if (sameDate(date, tomorrow)) return 'Tomorrow';
        return date.getFullYear() === today.getFullYear()
            ? date.toLocaleDateString(undefined, { month: 'short', day: 'numeric' })
            : date.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
    }

    function formatDateOnly(value) {
        if (!value) return '';
        return new Date(value).toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
    }

    function formatCurrency(value) {
        const amount = Number(value || 0);
        return amount.toLocaleString(undefined, { style: 'currency', currency: 'USD', minimumFractionDigits: amount >= 1 ? 2 : 4, maximumFractionDigits: amount >= 1 ? 2 : 4 });
    }

    function formatPercent(value) {
        if (value === null || value === undefined || value === '') return 'n/a';
        const number = Number(value);
        if (!Number.isFinite(number)) return 'n/a';
        return `${number > 0 ? '+' : ''}${number.toFixed(Math.abs(number) >= 10 ? 0 : 1)}%`;
    }

    function formatBytes(value) {
        const bytes = Number(value || 0);
        if (!Number.isFinite(bytes) || bytes <= 0) return '0 B';
        const units = ['B', 'KB', 'MB', 'GB', 'TB'];
        const index = Math.min(units.length - 1, Math.floor(Math.log(bytes) / Math.log(1024)));
        const amount = bytes / (1024 ** index);
        return `${amount.toFixed(amount >= 10 || index === 0 ? 0 : 1)} ${units[index]}`;
    }

    function formatDuration(seconds) {
        const totalSeconds = Math.max(0, Number(seconds || 0));
        if (!Number.isFinite(totalSeconds) || totalSeconds <= 0) return '0s';
        if (totalSeconds < 60) return `${Math.round(totalSeconds)}s`;
        const minutes = totalSeconds / 60;
        if (minutes < 60) return `${minutes.toFixed(minutes >= 10 ? 0 : 1)}m`;
        const hours = minutes / 60;
        return `${hours.toFixed(hours >= 10 ? 0 : 1)}h`;
    }

    function adminServerStatusLabel(status) {
        return { healthy: 'Healthy', watch: 'Watch', critical: 'Upgrade soon' }[String(status || '').toLowerCase()] || 'Unknown';
    }

    function adminServerStatusMeta(server = {}) {
        const signals = normalizeList(server.signals);
        if (signals.length) return `${signals.length} signal${signals.length === 1 ? '' : 's'} to review`;
        const checkedAt = server.checked_at || server.checkedAt;
        return checkedAt ? `Checked ${formatTime(checkedAt)}` : 'No upgrade signals';
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

    function formatCompactMeridiemTime(value) {
        if (!value) return '';
        const date = value instanceof Date ? value : new Date(value);
        if (Number.isNaN(date.getTime())) return '';
        return date
            .toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' })
            .replace(/:00(?=\s)/, '')
            .replace(/\s/g, '')
            .toLowerCase();
    }

    function formatCompactTimeRange(startValue, endValue) {
        const start = parseLocalDate(startValue);
        const end = parseLocalDate(endValue);
        if (Number.isNaN(start.getTime())) return '';
        if (Number.isNaN(end.getTime()) || end <= start) return formatCompactMeridiemTime(start);
        const startLabel = formatCompactMeridiemTime(start);
        const endLabel = formatCompactMeridiemTime(end);
        const startMeridiem = start.getHours() >= 12 ? 'pm' : 'am';
        const endMeridiem = end.getHours() >= 12 ? 'pm' : 'am';
        return startMeridiem === endMeridiem
            ? `${startLabel.replace(new RegExp(`${startMeridiem}$`), '')}-${endLabel}`
            : `${startLabel}-${endLabel}`;
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
            yearly: 'Yearly',
            specific_days: 'Specific days',
            interval: 'Every interval',
        }[value] || '';
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
        const date = parseLocalDate(value);
        date.setHours(0, 0, 0, 0);
        return date.toISOString();
    }

    function fromDateInputEndExclusive(value) {
        if (!value) return null;
        const date = parseLocalDate(value);
        date.setHours(0, 0, 0, 0);
        return date.toISOString();
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

    function userInitials(name = '', email = '') {
        const source = String(name || email || 'Account').trim();
        const words = source.includes('@') ? [source.charAt(0)] : source.split(/\s+/).filter(Boolean);
        return words.slice(0, 2).map((word) => word.charAt(0).toUpperCase()).join('') || 'A';
    }

    function capitalize(value) {
        return String(value).charAt(0).toUpperCase() + String(value).slice(1);
    }

    function scheduleOnboardingTourLayout() {
        window.cancelAnimationFrame(onboardingTourLayoutFrame);
        onboardingTourLayoutFrame = window.requestAnimationFrame(updateOnboardingTourLayout);
    }

    function onboardingTourStepTargets(step = onboardingTourStep()) {
        const selectors = step.selectors || (step.selector ? [step.selector] : []);
        return selectors.flatMap((selector) => Array.from(mount.querySelectorAll(selector)))
            .filter((element) => {
                const rect = element.getBoundingClientRect();
                const style = window.getComputedStyle(element);
                return rect.width > 0
                    && rect.height > 0
                    && style.visibility !== 'hidden'
                    && style.display !== 'none';
            });
    }

    function onboardingTourTargetMetrics(step = onboardingTourStep()) {
        const targets = onboardingTourStepTargets(step);
        if (!targets.length) return null;
        const viewportWidth = window.innerWidth;
        const viewportHeight = window.innerHeight;
        const union = targets.reduce((rect, target) => {
            const next = target.getBoundingClientRect();
            if (!rect) return {
                left: next.left,
                top: next.top,
                right: next.right,
                bottom: next.bottom,
            };
            return {
                left: Math.min(rect.left, next.left),
                top: Math.min(rect.top, next.top),
                right: Math.max(rect.right, next.right),
                bottom: Math.max(rect.bottom, next.bottom),
            };
        }, null);
        if (!union) return null;
        const rawWidth = Math.max(0, union.right - union.left);
        const rawHeight = Math.max(0, union.bottom - union.top);
        if (!rawWidth || !rawHeight) return null;
        const minDimension = Math.min(rawWidth, rawHeight);
        const maxDimension = Math.max(rawWidth, rawHeight);
        const padding = minDimension <= 56 ? 8 : minDimension <= 120 ? 10 : 12;
        const left = Math.max(8, union.left - padding);
        const top = Math.max(8, union.top - padding);
        const right = Math.min(viewportWidth - 8, union.right + padding);
        const bottom = Math.min(viewportHeight - 8, union.bottom + padding);
        const width = Math.max(0, right - left);
        const height = Math.max(0, bottom - top);
        if (!width || !height) return null;
        const radius = Math.max(
            minDimension <= 64 ? 14 : 16,
            Math.min(maxDimension >= 360 ? 22 : 24, minDimension * 0.28),
        );
        return { left, top, right, bottom, width, height, radius };
    }

    function scrollGuidedOnboardingContent() {
        window.requestAnimationFrame(() => {
            const content = mount.querySelector('[data-guided-content]') || mount.querySelector('.hb-guided-onboarding-content');
            if (!content) return;
            if (state.phase === 'subscription') {
                content.scrollTop = 0;
                return;
            }
            content.scrollTop = content.scrollHeight;
        });
    }

    function updateOnboardingTourLayout() {
        onboardingTourLayoutFrame = 0;
        const overlay = mount.querySelector('[data-onboarding-tour-overlay]');
        if (!overlay) return;
        const highlight = overlay.querySelector('[data-tour-highlight]');
        const card = overlay.querySelector('.hb-onboarding-tour-card');
        const topScrim = overlay.querySelector('[data-tour-scrim="top"]');
        const leftScrim = overlay.querySelector('[data-tour-scrim="left"]');
        const rightScrim = overlay.querySelector('[data-tour-scrim="right"]');
        const bottomScrim = overlay.querySelector('[data-tour-scrim="bottom"]');
        const metrics = onboardingTourTargetMetrics();
        if (!highlight || !card || !topScrim || !leftScrim || !rightScrim || !bottomScrim || !metrics) {
            overlay.classList.add('hb-onboarding-tour-no-target');
            return;
        }

        overlay.classList.remove('hb-onboarding-tour-no-target');
        const viewportWidth = window.innerWidth;
        const viewportHeight = window.innerHeight;
        const { left, top, right, bottom, width, height, radius } = metrics;

        highlight.style.left = `${left}px`;
        highlight.style.top = `${top}px`;
        highlight.style.width = `${width}px`;
        highlight.style.height = `${height}px`;
        highlight.style.borderRadius = `${radius}px`;

        topScrim.style.left = '0px';
        topScrim.style.top = '0px';
        topScrim.style.width = `${viewportWidth}px`;
        topScrim.style.height = `${top}px`;

        leftScrim.style.left = '0px';
        leftScrim.style.top = `${top}px`;
        leftScrim.style.width = `${left}px`;
        leftScrim.style.height = `${height}px`;

        rightScrim.style.left = `${right}px`;
        rightScrim.style.top = `${top}px`;
        rightScrim.style.width = `${Math.max(0, viewportWidth - right)}px`;
        rightScrim.style.height = `${height}px`;

        bottomScrim.style.left = '0px';
        bottomScrim.style.top = `${bottom}px`;
        bottomScrim.style.width = `${viewportWidth}px`;
        bottomScrim.style.height = `${Math.max(0, viewportHeight - bottom)}px`;

        const sideMargin = 16;
        const safeTop = 108;
        const dockHeight = viewportWidth <= 720 ? 96 : 104;
        const cardWidth = Math.min(390, viewportWidth - sideMargin * 2);
        card.style.width = `${cardWidth}px`;
        const cardHeight = card.offsetHeight || 178;
        const maxTop = Math.max(safeTop, viewportHeight - dockHeight - cardHeight - 18);
        const cardTop = Math.min(Math.max(Math.round(viewportHeight * 0.36), safeTop), maxTop);
        const cardLeft = Math.round((viewportWidth - cardWidth) / 2);
        card.style.left = `${Math.max(sideMargin, cardLeft)}px`;
        card.style.top = `${cardTop}px`;
        card.style.right = 'auto';
        card.style.bottom = 'auto';
        card.style.transform = 'none';
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
        requestAnimationFrame(() => {
            updateTimelineStickyOffsets(timeline);
            updateMultiDayRowVisibility(timeline);
        });
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

    function updateTimelineStickyOffsets(timeline) {
        if (!timeline) return;
        const head = timeline.querySelector('.hb-timeline-head');
        const multiDayRow = timeline.querySelector('[data-multi-day-row]');
        const hasVisibleMultiDay = multiDayRow && !multiDayRow.classList.contains('hb-multi-day-row-collapsed');
        const headHeight = head?.getBoundingClientRect().height || 0;
        const multiDayHeight = hasVisibleMultiDay ? multiDayRow.getBoundingClientRect().height || 0 : 0;
        timeline.style.setProperty('--hb-timeline-head-height', `${headHeight}px`);
        timeline.style.setProperty('--hb-multi-day-row-height', `${multiDayHeight}px`);
        timeline.classList.toggle('hb-timeline-visible-multi-day', Boolean(hasVisibleMultiDay));
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
        updateTimelineStickyOffsets(timeline);
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
        const message = String(error?.message || 'Something went wrong.');
        if (/failed to fetch/i.test(message)) return `Could not ${action}. Check your connection and try again.`;
        if (Number(error?.status) >= 500 || looksLikeInternalError(message)) {
            return `Could not ${action} right now. Please try again in a moment.`;
        }
        return message;
    }

    function looksLikeInternalError(message) {
        return /\b(SQLSTATE|PDOException|QueryException|Illuminate\\|Stack trace|Connection:|Database:|select \* from|no such table|undefined table|syntax error|\/Users\/|\/home\/forge\/)\b/i.test(String(message || ''));
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
                ${paywall ? '<div class="hb-paywall-actions"><a class="hb-button-secondary hb-paywall-cta" href="/pricing">View plans</a><button class="hb-icon-button hb-paywall-dismiss" type="button" data-dismiss-plan-limit-error aria-label="Dismiss upgrade notice" title="Dismiss">×</button></div>' : ''}
            </div>`;
    }

    function isPlanLimitMessage(message) {
        const normalized = String(message || '').toLowerCase();
        return normalized.includes('current plan includes')
            || normalized.includes('current plan has limited')
            || normalized.includes('available on premium');
    }

    function clearPlanLimitError() {
        if (isPlanLimitMessage(state.error)) {
            state.error = '';
        }
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
