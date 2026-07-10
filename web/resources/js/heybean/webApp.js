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
import {
    RealtimeCallDeduper,
    RealtimeResponseLifecycle,
    buildRealtimeResponseEvent,
    canQueueRealtimeFollowUp,
    extractRealtimeResponseTranscript,
    isVoiceFillerOnly,
    realtimeFollowUpExpiry,
    realtimeNeedsAppRuntime,
    shouldDeferAssistantMessage,
} from './realtimeVoiceTurn.js';

export function mountHeyBeanWebApp(mount) {
    const logoUrl = mount.dataset.logo || '/images/bean-logo.png';
    const initialMode = mount.dataset.authMode || 'login';
    const initialSelectedPlan = ['base', 'premium', 'pro'].includes(mount.dataset.selectedPlan) ? mount.dataset.selectedPlan : '';
    const initialBillingInterval = mount.dataset.selectedBillingInterval === 'yearly' ? 'yearly' : 'monthly';
    const initialBillingStatus = new URLSearchParams(window.location.search).get('billing') || '';
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
        guidedSignupMessages: [],
        guidedSignupName: '',
        guidedSignupEmail: '',
        guidedSignupPassword: '',
        guidedSignupThemeMode: 'light',
        guidedSignupPersonality: '',
        guidedSignupHomeCity: '',
        guidedSignupError: '',
        guidedSignupThinking: false,
        guidedSignupResponseVariationIndex: 0,
        onboardingTourPendingSubscription: false,
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
        memoryItems: [],
        memorySummaries: [],
        memoryHistory: [],
        memorySearch: '',
        memoryTypeFilter: '',
        memorySaving: false,
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
        approvals: [],
        blockers: [],
        activity: [],
        adminUsage: null,
        adminLiveLookup: null,
        adminPlanLimits: null,
        adminCoupons: null,
        adminUsageLoading: false,
        adminModelRegistry: null,
        adminHermesStatus: null,
        adminHermesUpdating: false,
        adminUserGrowthRange: 'last_30_days',
        adminArchivedIssuesOpen: false,
        issueReportSubmitting: false,
        googleStatus: null,
        googleAuthUrl: '',
        outlookStatus: null,
        outlookAuthUrl: '',
        messages: [],
        session: null,
        chatSessions: [],
        chatSessionHydrating: false,
        chatHistoryOpen: false,
        chatRunState: 'Ready',
        chatDraft: '',
        voiceWakeListening: false,
        voiceRecording: false,
        voiceProcessing: false,
        chatQueue: [],
        editingChatMessageId: '',
        activeBeanWorkMessageId: null,
        beanWorkItems: [],
        commandCenterChatCollapsed: false,
        commandCenterAgendaRatio: 1 / 3,
        commandCenterChatRatio: 1 / 3,
        onboardingJustCompleted: false,
        onboardingTourActive: false,
        onboardingTourStep: 0,
        calendarRefreshing: false,
        dashboardDataLoading: false,
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
    let realtimePeerConnection = null;
    let realtimeDataChannel = null;
    let realtimeLocalStream = null;
    let realtimeRemoteAudio = null;
    let realtimeSession = null;
    let realtimeVoiceActive = false;
    let realtimeWakeActivated = false;
    let realtimeAppConversationActive = false;
    let realtimeFollowUpUntil = 0;
    let realtimeFollowUpTimer = 0;
    let realtimePendingTranscript = '';
    let realtimeAssistantDraft = '';
    let realtimeLaravelTurnInFlight = false;
    let realtimeSuppressNextAssistantTranscript = false;
    let realtimeResponseActive = false;
    let realtimeLastCommandKey = '';
    let realtimeLastCommandAt = 0;
    let realtimeTurnGuardUntil = 0;
    let realtimeIgnoreInputUntil = 0;
    let realtimeQueuedFollowUpTranscript = '';
    let realtimeQueuedFollowUpTimer = 0;
    let realtimeToolCalls = new Map();
    const realtimeCallDeduper = new RealtimeCallDeduper();
    const realtimeResponseLifecycle = new RealtimeResponseLifecycle();

    const guidedSignupPersonalities = [
        {
            key: 'balanced',
            label: 'Balanced helper',
            description: 'A calm, practical default that keeps replies concise and useful.',
        },
        {
            key: 'coach',
            label: 'Motivating coach',
            description: 'An encouraging style that gives gentle nudges and helps you keep momentum.',
        },
        {
            key: 'organizer',
            label: 'Detail organizer',
            description: 'A structured planner that pays close attention to dates, times, and follow-up.',
        },
        {
            key: 'creative',
            label: 'Creative partner',
            description: 'A brainstorming partner that turns ideas into practical lists, notes, and plans.',
        },
        {
            key: 'direct',
            label: 'Direct operator',
            description: 'A brief, action-first style that leads with the answer or completed work.',
        },
        {
            key: 'gentle',
            label: 'Gentle companion',
            description: 'A patient, low-pressure style that keeps busy days feeling manageable.',
        },
    ];
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

    let commandCenterResizeDrag = null;
    let timelineDrag = null;
    let timelineSuppressClick = false;
    let onboardingTourLayoutFrame = 0;
    let dashboardChangeAbort = null;
    let dashboardChangeLoopActive = false;
    let dashboardRefreshTimer = 0;
    let adminCommandRunPollTimer = 0;
    let adminRealtimeRefreshTimer = 0;
    let deferredDashboardRenderPending = false;
    let deferredDashboardRenderTimer = 0;
    let dashboardRefreshGeneration = 0;
    let localResourceSequence = -1;
    const noteAutosaveTimers = new Map();
    const noteAutosaveDelay = 650;
    let chatRequestCounter = 0;
    let chatQueueCounter = 0;
    let activeChatRequestId = 0;
    let beanWorkEventPollTimer = 0;
    let beanWorkEventPollToken = 0;
    let beanWorkStatusClearTimer = 0;
    let beanWorkStatusHoldUntil = 0;
    let beanWorkStatusMinUntil = 0;
    let beanWorkEventFloorId = 0;
    const beanWorkAppliedEventIds = new Set();
    const beanDashboardRefreshEventIds = new Set();
    const cancelledChatRequestIds = new Set();

    boot();
    bindResponsiveCalendar();
    bindCurrentTimeTicker();
    bindDashboardRealtimeFallbacks();
    bindOnboardingTourViewport();
    bindDeferredDashboardRenderFlush();

    async function boot() {
        if (initialMode === 'subscribe') {
            await loadSubscriptionPage();
            return;
        }
        if (state.token) {
            await loadSignedIn();
        } else {
            state.phase = initialMode === 'register' ? 'guidedOnboarding' : 'signedOut';
            if (state.phase === 'guidedOnboarding') resetGuidedSignupState();
            render();
        }
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
            const cacheApplied = Boolean(cachedWorkspaceId && applyDashboardCache(cachedWorkspaceId));
            state.phase = 'signedIn';
            state.dashboardDataLoading = !cacheApplied;
            state.error = '';
            applyBillingReturnNotice();
            if (needsBeanOnboarding()) {
                state.selected = 'bean';
                state.chatRunState = 'Onboarding';
            }
            state.session = null;
            state.messages = [];
            state.chatSessionHydrating = !needsBeanOnboarding();
            if (state.selected === 'admin') {
                loadAdminUsage();
            }
            if (!deferInitialRender) {
                render();
            }
            startDashboardChangeFeed();
            loadChatSessions({ resumeToday: true }).catch(() => {
                // Chat history should hydrate opportunistically and never block the app shell.
            }).finally(() => {
                state.chatSessionHydrating = false;
                render();
            });

            const notesAllowed = notesEnabled();
            const [summary, tasks, pastTasks, reminders, calendar, noteFolders, notes, memoryItems, memorySummaries, memoryHistory, categories, googleStatus, outlookStatus, subscription, billingPayment] = await Promise.all([
                recover(api(workspaceScopedPath('/today')), state.summary || {}),
                recover(api(workspaceScopedPath('/tasks')), state.tasks),
                recover(api(workspaceScopedPath('/tasks/past')), []),
                recover(api(workspaceScopedPath('/reminders')), state.reminders),
                recover(api(workspaceScopedPath('/calendar-events?skip_google_sync=1&skip_outlook_sync=1')), state.calendar),
                notesAllowed ? recover(api(workspaceScopedPath('/note-folders')), state.noteFolders) : Promise.resolve([]),
                notesAllowed ? recover(api(workspaceScopedPath('/notes')), state.notes) : Promise.resolve([]),
                recover(api(workspaceScopedPath('/memory-items')), state.memoryItems),
                recover(api(workspaceScopedPath('/memory-summaries')), state.memorySummaries),
                recover(api(workspaceScopedPath('/memory/request-history?limit=10')), state.memoryHistory),
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
            state.memoryItems = normalizeList(memoryItems);
            state.memorySummaries = normalizeList(memorySummaries);
            state.memoryHistory = normalizeList(memoryHistory);
            state.categories = normalizeList(categories);
            state.approvals = normalizeList(summary?.approvals);
            state.blockers = normalizeList(summary?.blockers);
            state.activity = normalizeList(summary?.activity_events);
            state.googleStatus = googleStatus;
            state.outlookStatus = outlookStatus;
            state.phase = 'signedIn';
            state.dashboardDataLoading = false;
            state.error = refreshError ? friendlyError(refreshError, 'refresh your latest data') : '';
            applyBillingReturnNotice();
            if (needsBeanOnboarding()) {
                state.selected = 'bean';
                state.chatRunState = 'Onboarding';
            }
            saveDashboardCache();
            renderDashboardDataUpdate({ deferIfEditing: true });
            refreshCalendarInBackground();
        } catch (error) {
            stopDashboardChangeFeed();
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
                memoryItems: state.memoryItems,
                memorySummaries: state.memorySummaries,
                memoryHistory: state.memoryHistory,
                categories: state.categories,
                approvals: state.approvals,
                blockers: state.blockers,
                activity: state.activity,
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
            state.memoryItems = normalizeList(cached.memoryItems);
            state.memorySummaries = normalizeList(cached.memorySummaries);
            state.memoryHistory = normalizeList(cached.memoryHistory);
            state.categories = normalizeList(cached.categories);
            state.approvals = normalizeList(cached.approvals);
            state.blockers = normalizeList(cached.blockers);
            state.activity = normalizeList(cached.activity);
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
            approvals: [],
            blockers: [],
            activity_events: [],
        };
        state.tasks = [];
        state.reminders = [];
        state.calendar = [];
        state.noteFolders = [];
        state.notes = [];
        state.memoryItems = [];
        state.memorySummaries = [];
        state.memoryHistory = [];
        state.selectedNoteId = '';
        state.selectedNoteFolderId = 'all';
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
            noteFolders: state.noteFolders,
            notes: state.notes,
            memoryItems: state.memoryItems,
            memorySummaries: state.memorySummaries,
            memoryHistory: state.memoryHistory,
            categories: state.categories,
            approvals: state.approvals,
            blockers: state.blockers,
            activity: state.activity,
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
        state.memoryItems = snapshot.memoryItems;
        state.memorySummaries = snapshot.memorySummaries;
        state.memoryHistory = snapshot.memoryHistory;
        state.categories = snapshot.categories;
        state.approvals = snapshot.approvals;
        state.blockers = snapshot.blockers;
        state.activity = snapshot.activity;
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

    function profileOnboardingComplete(profile = currentAgentProfile()) {
        const onboarding = profileOnboarding(profile);
        return onboarding.completed === true || onboarding.completed === 1 || onboarding.completed === 'true';
    }

    function profilePreferencesReady(profile = currentAgentProfile()) {
        if (!profileOnboardingComplete(profile)) return false;
        return profilePriorities(profile).length > 0 || profileOnboardingContext(profile).trim() !== '';
    }

    function needsBeanOnboarding() {
        return false;
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

    function userNeedsSignupPaywall(user = state.user, subscription = state.subscriptionSummary) {
        if (!user) return false;
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
        state.guidedSignupMessages = normalizeList(options.messages).length
            ? normalizeList(options.messages)
            : [{ id: `bean-${Date.now()}`, bean: true, text: 'Hello, please enter your name below.' }];
        state.guidedSignupName = options.name || '';
        state.guidedSignupEmail = options.email || '';
        state.guidedSignupPassword = '';
        state.guidedSignupThemeMode = options.themeMode || 'light';
        state.guidedSignupPersonality = options.personality || '';
        state.guidedSignupHomeCity = options.homeCity || '';
        state.guidedSignupError = options.error || '';
        state.guidedSignupThinking = false;
        state.guidedSignupResponseVariationIndex = 0;
        state.busy = false;
        state.notice = options.notice || '';
    }

    function pushGuidedSignupMessage(bean, text, options = {}) {
        state.guidedSignupMessages = [
            ...normalizeList(state.guidedSignupMessages),
            {
                id: options.id || `${bean ? 'bean' : 'user'}-${Date.now()}-${Math.random().toString(16).slice(2, 8)}`,
                bean,
                text,
                masked: Boolean(options.masked),
            },
        ];
    }

    function guidedSignupBean(text, options = {}) {
        pushGuidedSignupMessage(true, text, options);
    }

    function guidedSignupUser(text, options = {}) {
        pushGuidedSignupMessage(false, text, options);
    }

    function guidedSignupInputLocked() {
        return state.busy || state.guidedSignupThinking || state.guidedSignupStep === 'plan';
    }

    function nextGuidedSignupResponseDelay() {
        return 2000 + ((state.guidedSignupResponseVariationIndex * 431) % 900);
    }

    async function showGuidedSignupThinking() {
        if (state.phase !== 'guidedOnboarding') return;
        state.guidedSignupThinking = true;
        render();
        await new Promise((resolve) => window.setTimeout(resolve, nextGuidedSignupResponseDelay()));
        state.guidedSignupResponseVariationIndex += 1;
        if (state.phase !== 'guidedOnboarding') return;
        state.guidedSignupThinking = false;
        render();
    }

    async function respondGuidedSignupBean(text, options = {}) {
        await showGuidedSignupThinking();
        if (state.phase !== 'guidedOnboarding') return;
        guidedSignupBean(text, options);
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
        const folderId = String(state.selectedNoteFolderId || 'all');
        const search = String(state.notesSearch || '').trim().toLowerCase();
        return normalizeNotes(state.notes).filter((note) => {
            const noteFolderId = String(note.note_folder_id || note.noteFolderId || '');
            const folderMatch = folderId === 'all'
                || (folderId === 'pinned' && Boolean(note.is_pinned ?? note.isPinned))
                || (folderId === 'unfiled' && !noteFolderId)
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
        const folderId = String(state.selectedNoteFolderId || 'all');
        if (folderId === 'pinned') return 'Pinned';
        if (folderId === 'unfiled') return 'Unfiled';
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

    function notePlainTextFromHtml(html) {
        const div = document.createElement('div');
        div.innerHTML = String(html || '').replace(/<br\s*\/?>/gi, '\n');
        return noteBodyPlainText(div).trim();
    }

    function noteBodyPlainText(bodyNode) {
        if (!bodyNode) return '';
        const nodes = bodyNode.childNodes?.length ? Array.from(bodyNode.childNodes) : [bodyNode];
        return nodes
            .map(noteNodePlainText)
            .join('\n')
            .replace(/\u00a0/g, ' ')
            .replace(/\n{3,}/g, '\n\n')
            .replace(/\n+$/g, '');
    }

    function noteNodePlainText(node) {
        if (!node) return '';
        if (node.nodeType === Node.TEXT_NODE) return node.textContent || '';
        if (node.nodeType !== Node.ELEMENT_NODE) return '';
        const element = node;
        if (element.matches?.('.hb-note-checkbox-marker')) {
            return element.classList.contains('hb-note-checkbox-marker-checked') ? '☑ ' : '☐ ';
        }
        if (element.matches?.('.hb-note-bullet-marker')) return '• ';
        if (element.tagName === 'BR') return '\n';
        if (element.tagName === 'HR') return '---';
        return Array.from(element.childNodes || []).map(noteNodePlainText).join('');
    }

    function noteTextToHtml(text) {
        const lines = String(text || '').split('\n');
        return lines.map((line) => {
            const escaped = escapeHtml(line || '');
            const markerMatch = line.match(/^(\s*)(☐|☑|•)\s?(.*)$/);
            if (markerMatch) {
                const [, indent, marker, rest] = markerMatch;
                const checked = marker === '☑';
                const bullet = marker === '•';
                const visualMarker = bullet
                    ? '<span class="hb-note-bullet-marker" aria-hidden="true">•</span>'
                    : `<span class="hb-note-checkbox-marker ${checked ? 'hb-note-checkbox-marker-checked' : ''}" aria-hidden="true">${checked ? '☑' : '☐'}</span>`;
                return `<div>${escapeHtml(indent)}${visualMarker}<span>${escapeHtml(rest || '')}</span></div>`;
            }
            return `<div>${escaped || '<br>'}</div>`;
        }).join('');
    }

    function noteTextLineAt(text, offset) {
        const safeOffset = Math.max(0, Math.min(Number(offset) || 0, text.length));
        const before = text.lastIndexOf('\n', Math.max(0, safeOffset - 1));
        const after = text.indexOf('\n', safeOffset);
        const start = before === -1 ? 0 : before + 1;
        const end = after === -1 ? text.length : after;
        return { start, end, text: text.slice(start, end) };
    }

    function noteLineMarker(line) {
        const match = String(line || '').match(/^(\s*)(☐|☑|•)(\s?)/);
        if (!match) return null;
        const marker = `${match[2]} `;
        return {
            indent: match[1] || '',
            marker,
            start: match[1].length,
            end: match[1].length + match[2].length + (match[3] ? 1 : 0),
        };
    }

    function editableTextOffset(root) {
        const selection = window.getSelection();
        if (!root || !selection?.rangeCount) return 0;
        const range = selection.getRangeAt(0);
        if (!root.contains(range.endContainer)) return noteBodyPlainText(root).length;
        const clone = range.cloneRange();
        clone.selectNodeContents(root);
        clone.setEnd(range.endContainer, range.endOffset);
        return clone.toString().length;
    }

    function setEditableTextOffset(root, offset) {
        if (!root) return;
        const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);
        let remaining = Math.max(0, Number(offset) || 0);
        let node = walker.nextNode();
        while (node) {
            const length = node.textContent.length;
            if (remaining <= length) {
                const range = document.createRange();
                range.setStart(node, remaining);
                range.collapse(true);
                const selection = window.getSelection();
                selection.removeAllRanges();
                selection.addRange(range);
                return;
            }
            remaining -= length;
            node = walker.nextNode();
        }
        const range = document.createRange();
        range.selectNodeContents(root);
        range.collapse(false);
        const selection = window.getSelection();
        selection.removeAllRanges();
        selection.addRange(range);
    }

    function replaceNoteBodyText(bodyNode, text, caretOffset = null) {
        if (!bodyNode) return;
        bodyNode.innerHTML = noteTextToHtml(text);
        setEditableTextOffset(bodyNode, caretOffset ?? text.length);
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
            : state.phase === 'guidedOnboarding'
                ? guidedOnboardingMarkup()
            : state.phase === 'subscription'
                ? subscriptionSignupMarkup()
                : signedOutMarkup();
        bindCommonActions();
        if (state.phase === 'subscription') bindSubscriptionActions();
        if (state.phase === 'signedIn') bindSignedInActions();
        if (state.phase === 'guidedOnboarding' || state.phase === 'subscription') {
            scrollGuidedOnboardingThread();
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
                    <button class="hb-button-ghost" type="button" data-auth-mode="register">Create an account</button>
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

    async function submitGuidedOnboarding(event) {
        event.preventDefault();
        if (guidedSignupInputLocked()) return;
        const input = event.currentTarget.querySelector('[name="message"]');
        const value = String(input?.value || '').trim();
        if (!value) return;
        if (input) input.value = '';
        state.guidedSignupError = '';
        render();
        const step = state.guidedSignupStep;
        if (step === 'name') {
            await handleGuidedSignupName(value);
            return;
        }
        if (step === 'themeMode') {
            const key = guidedThemeModeKeyFromText(value);
            if (!key) {
                state.guidedSignupError = 'Choose Light, Dark, or Auto.';
                render();
                return;
            }
            selectGuidedThemeMode(key);
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
        if (step === 'personality') {
            const personality = guidedPersonalityKeyFromText(value);
            if (!personality) {
                state.guidedSignupError = 'Pick one of the personality options, or type the one you want.';
                render();
                return;
            }
            selectGuidedPersonality(personality);
            return;
        }
        if (step === 'location') {
            if (guidedSignupValueIsSkip(value)) {
                await skipGuidedLocation();
                return;
            }
            state.guidedSignupError = 'Tap Allow location or Skip so Bean handles this cleanly.';
            render();
            return;
        }
        if (step === 'tourChoice') {
            const normalized = value.toLowerCase();
            if (/\b(yes|yeah|yep|sure|show|tour)\b/.test(normalized)) {
                guidedSignupUser(value);
                render();
                await launchGuidedOnboardingTour();
                return;
            }
            if (/\b(no|skip|straight|dashboard|plan)\b/.test(normalized)) {
                guidedSignupUser(value);
                render();
                await goToGuidedPlan(true);
                return;
            }
            state.guidedSignupError = 'Please answer yes for a quick tour, or no to go straight to plan setup.';
            render();
        }
    }

    async function handleGuidedSignupName(value) {
        const name = value.trim();
        if (name.length < 2) {
            state.guidedSignupError = 'Please enter the name you want Bean to use.';
            render();
            return;
        }
        state.guidedSignupName = name;
        guidedSignupUser(name);
        await respondGuidedSignupBean(`Nice to meet you, ${name}. Do you prefer light or dark mode? You can also choose Auto, and you can change this anytime in Appearance settings.`);
        state.guidedSignupStep = 'themeMode';
        render();
    }

    function guidedThemeModeKeyFromText(value) {
        const normalized = String(value || '').trim().toLowerCase();
        if (!normalized) return '';
        if (['system', 'device', 'automatic'].includes(normalized)) return 'auto';
        return ['light', 'dark', 'auto'].find((key) => key === normalized) || '';
    }

    async function selectGuidedThemeMode(key) {
        const themeMode = guidedThemeModeKeyFromText(key);
        if (!themeMode) return;
        state.guidedSignupThemeMode = themeMode;
        state.guidedSignupError = '';
        const mode = themeModesByKey.get(themeMode);
        guidedSignupUser(mode?.label || themeMode);
        if (themeMode === 'light') {
            await respondGuidedSignupBean('Ok, I\'ll keep it in Light mode. What email address should I use for your account? Please text it here.');
        } else if (themeMode === 'dark') {
            await respondGuidedSignupBean('Dark mode it is. What email address should I use for your account? Please text it here.');
        } else {
            await respondGuidedSignupBean('Auto mode it is. What email address should I use for your account? Please text it here.');
        }
        state.guidedSignupStep = 'email';
        render();
    }

    async function handleGuidedSignupEmail(value) {
        const email = value.trim().toLowerCase();
        guidedSignupUser(email);
        if (!looksLikeGuidedSignupEmail(email)) {
            await respondGuidedSignupBean('That email format does not look right. Please send it like name@example.com, without extra punctuation.');
            render();
            return;
        }
        state.busy = true;
        render();
        try {
            const availability = await api('/auth/email-availability', { method: 'POST', body: { email } });
            state.busy = false;
            if (!availability.available) {
                await respondGuidedSignupBean('That email is already taken. Please send a different email address for this account.');
                render();
                return;
            }
            state.guidedSignupEmail = availability.email;
            await respondGuidedSignupBean('Thanks. Now text the password you would like for this account. I will mask it here.');
            state.guidedSignupStep = 'password';
            render();
        } catch (_) {
            state.busy = false;
            await respondGuidedSignupBean('I could not check that email right now. Please try the email again in a moment.');
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
        guidedSignupUser('Password saved', { masked: true });
        state.busy = true;
        render();
        try {
            const result = await api('/auth/register', {
                method: 'POST',
                body: {
                    name: state.guidedSignupName,
                    email: state.guidedSignupEmail,
                    password: value,
                    password_confirmation: value,
                    theme_mode: state.guidedSignupThemeMode,
                    ...(state.selectedPlan ? { plan: state.selectedPlan } : {}),
                    billing_interval: normalizedBillingInterval(state.selectedBillingInterval),
                },
            });
            persistToken(result.token, true);
            state.user = result.user || null;
            state.subscriptionSummary = null;
            state.busy = false;
            await respondGuidedSignupBean('Your account has been created. Check your email to verify. Next, what personality type would you like me to have?');
            state.guidedSignupStep = 'personality';
            render();
        } catch (error) {
            state.busy = false;
            state.guidedSignupError = friendlyError(error, 'create your account');
            render();
        }
    }

    function guidedPersonalityKeyFromText(value) {
        const normalized = String(value || '').toLowerCase();
        const direct = guidedSignupPersonalities.find((option) => normalized.includes(option.key) || normalized.includes(option.label.toLowerCase().split(' ')[0]));
        if (direct) return direct.key;
        if (normalized.includes('balanced')) return 'balanced';
        if (normalized.includes('coach') || normalized.includes('motivat')) return 'coach';
        if (normalized.includes('organizer') || normalized.includes('detail')) return 'organizer';
        if (normalized.includes('creative')) return 'creative';
        if (normalized.includes('direct') || normalized.includes('operator')) return 'direct';
        if (normalized.includes('gentle') || normalized.includes('companion')) return 'gentle';
        return '';
    }

    async function selectGuidedPersonality(key) {
        const option = guidedSignupPersonalities.find((item) => item.key === key) || guidedSignupPersonalities[0];
        state.guidedSignupPersonality = option.key;
        guidedSignupUser(option.label);
        await respondGuidedSignupBean('Perfect. You can also adjust Bean preferences in the settings menu later. Next, can I access your location so I can see what city we are in? This helps with weather related questions and planning.');
        state.guidedSignupStep = 'location';
        state.guidedSignupError = '';
        render();
    }

    function guidedSignupValueIsSkip(value) {
        return /\b(skip|no|not now|later)\b/i.test(String(value || ''));
    }

    async function allowGuidedLocation(position) {
        state.busy = true;
        state.guidedSignupError = '';
        render();
        try {
            const city = await currentGuidedCityFromPosition(position);
            state.guidedSignupHomeCity = city;
            guidedSignupUser(`Shared city: ${city}`);
            await saveGuidedSignupPreferences();
            state.busy = false;
            await respondGuidedSignupBean(`Thanks. I will remember ${city} for weather and local planning. Next, choose your plan so your ${subscriptionTrialDays}-day free trial is ready. After that I will show you the dashboard and help import your calendar.`);
            openGuidedPlanSelection();
        } catch (error) {
            state.busy = false;
            state.guidedSignupError = error instanceof Error ? error.message : 'I could not read your city. You can skip this and add it later in Settings.';
            render();
        }
    }

    function requestGuidedLocationFromClick() {
        state.guidedSignupError = '';
        if (!window.isSecureContext && window.location?.hostname !== 'localhost' && window.location?.hostname !== '127.0.0.1') {
            state.guidedSignupError = 'Location permission only works on a secure connection. Open HeyBean over HTTPS, then try Allow location again.';
            render();
            return;
        }
        if (!navigator.geolocation) {
            state.guidedSignupError = 'Location is not available in this browser. You can skip this and add a city later in Settings.';
            render();
            return;
        }
        state.busy = true;
        render();
        navigator.geolocation.getCurrentPosition(
            (position) => {
                void allowGuidedLocation(position);
            },
            (error) => {
                state.busy = false;
                state.guidedSignupError = guidedLocationErrorMessage(error);
                render();
            },
            {
                enableHighAccuracy: false,
                timeout: 8000,
                maximumAge: 300000,
            },
        );
    }

    async function skipGuidedLocation() {
        guidedSignupUser('Skip location');
        state.guidedSignupHomeCity = '';
        state.busy = true;
        state.guidedSignupError = '';
        render();
        try {
            await saveGuidedSignupPreferences();
            state.busy = false;
            await respondGuidedSignupBean(`No worries. We can skip that for now. Next, choose your plan so your ${subscriptionTrialDays}-day free trial is ready. After that I will show you the dashboard and help import your calendar.`);
            openGuidedPlanSelection();
        } catch (error) {
            state.busy = false;
            state.guidedSignupError = friendlyError(error, 'save your Bean preferences');
            render();
        }
    }

    function openGuidedPlanSelection() {
        state.phase = 'subscription';
        state.busy = false;
        state.guidedSignupStep = 'plan';
        state.error = '';
        history.pushState({}, '', `/subscribe?plan=${encodeURIComponent(state.selectedPlan || 'premium')}&billing_interval=${encodeURIComponent(normalizedBillingInterval(state.selectedBillingInterval))}`);
        render();
    }

    async function saveGuidedSignupPreferences() {
        const personality = state.guidedSignupPersonality || 'balanced';
        const context = [
            'Completed guided Bean signup onboarding.',
            `Preferred Bean personality: ${signupPersonalityBaseLabel(personality)}.`,
            state.guidedSignupHomeCity ? `City-level location: ${state.guidedSignupHomeCity}.` : '',
        ].filter(Boolean).join(' ');
        state.user = await api('/auth/me', {
            method: 'PATCH',
            body: {
                agent_personality: personality,
                onboarding_priorities: ['Planning', 'Reminders', 'Focus'],
                onboarding_context: context,
                ...(state.guidedSignupHomeCity ? { home_city: state.guidedSignupHomeCity } : {}),
            },
        });
    }

    function guidedLocationErrorMessage(error) {
        if (error instanceof Error) return error.message;
        if (error?.code === 1) {
            return 'Location access was blocked. Click Allow in the browser location prompt, or use the location control next to the address bar and allow this site, then try again.';
        }
        if (error?.code === 2) {
            return 'Your browser could not determine your location. Please try Allow location again, or skip this and add a city later in Settings.';
        }
        if (error?.code === 3) {
            return 'Location lookup timed out. Please try Allow location again, or skip this and add a city later in Settings.';
        }
        return 'I could not read your city. Please try Allow location again, or skip this and add it later in Settings.';
    }

    async function currentGuidedCityFromPosition(position) {
        const latitude = position?.coords?.latitude;
        const longitude = position?.coords?.longitude;
        if (!Number.isFinite(latitude) || !Number.isFinite(longitude)) {
            throw new Error('I could not read your city. You can skip this and add it later in Settings.');
        }
        const response = await fetch(`https://nominatim.openstreetmap.org/reverse?format=jsonv2&lat=${encodeURIComponent(latitude)}&lon=${encodeURIComponent(longitude)}`, {
            headers: { Accept: 'application/json' },
        }).catch(() => null);
        const payload = response && response.ok ? await response.json().catch(() => null) : null;
        const address = payload?.address || {};
        const city = [address.city || address.town || address.village || address.county, address.state]
            .filter(Boolean)
            .map((part) => String(part).trim())
            .filter(Boolean)
            .slice(0, 2)
            .join(', ');
        if (!city) {
            throw new Error('I could not identify your city from this location. You can skip this and add it later in Settings.');
        }
        return city;
    }

    async function launchGuidedOnboardingTour() {
        if (!state.user) return;
        state.guidedSignupStep = 'tour';
        state.busy = true;
        state.guidedSignupError = '';
        state.phase = 'loading';
        render();
        const pendingSubscription = userNeedsSignupPaywall(state.user);
        await loadSignedIn({ deferInitialRender: true });
        state.busy = false;
        state.onboardingTourPendingSubscription = pendingSubscription;
        activateOnboardingTourStep(0);
        render();
    }

    async function goToGuidedPlan(skipTour = false) {
        await respondGuidedSignupBean(
            skipTour
                ? 'Sounds good. We will skip the tour and finish setup with your plan. Pick whichever option fits your needs.'
                : `That is the quick tour. Last step: choose your plan so your ${subscriptionTrialDays}-day free trial is ready.`,
        );
        state.phase = 'subscription';
        state.busy = false;
        state.guidedSignupStep = 'plan';
        state.error = '';
        history.pushState({}, '', `/subscribe?plan=${encodeURIComponent(state.selectedPlan || 'premium')}&billing_interval=${encodeURIComponent(normalizedBillingInterval(state.selectedBillingInterval))}`);
        render();
    }

    function guidedOnboardingMarkup() {
        const step = state.guidedSignupStep;
        const showComposer = step !== 'tour';
        const showInstruction = step === 'name' && normalizeList(state.guidedSignupMessages).length === 1;
        return `
            <div class="hb-app">
                <main class="hb-guided-onboarding-shell">
                    <div class="hb-guided-onboarding-topbar">
                        <button class="hb-button-ghost" type="button" data-auth-mode="login">Login</button>
                    </div>
                    <section class="hb-guided-onboarding-stage">
                        <div class="hb-guided-onboarding-thread" data-guided-thread>
                            ${normalizeList(state.guidedSignupMessages).map((message) => guidedOnboardingMessageMarkup(message)).join('')}
                            ${state.guidedSignupThinking ? guidedOnboardingThinkingMarkup() : ''}
                            ${step === 'themeMode' ? guidedThemeModePanelMarkup() : ''}
                            ${step === 'personality' ? guidedPersonalityPanelMarkup() : ''}
                            ${step === 'location' ? guidedLocationPanelMarkup() : ''}
                            ${step === 'tourChoice' ? guidedTourChoicePanelMarkup() : ''}
                            ${guidedOnboardingStatusMarkup()}
                            ${step === 'tour' ? '<div class="hb-guided-onboarding-thinking"><span class="hb-spinner hb-spinner-tiny" aria-hidden="true"></span><span>Preparing your dashboard tour...</span></div>' : ''}
                        </div>
                    </section>
                </main>
                ${showInstruction ? '<div class="hb-guided-onboarding-instruction">Tap to text Bean</div>' : ''}
                ${showComposer ? guidedOnboardingComposerMarkup() : ''}
                <div class="hb-guided-onboarding-bean-dock" aria-hidden="true"><img src="${escapeAttr(logoUrl)}" alt=""></div>
            </div>`;
    }

    function guidedOnboardingMessageMarkup(message) {
        return `
            <article class="hb-guided-message ${message.bean ? 'hb-guided-message-bean' : 'hb-guided-message-user'}">
                <strong>${message.bean ? 'Bean' : 'You'}</strong>
                <p>${escapeHtml(message.masked ? '************' : message.text)}</p>
            </article>`;
    }

    function guidedOnboardingThinkingMarkup() {
        return `
            <article class="hb-guided-message hb-guided-message-bean hb-guided-message-thinking" aria-live="polite">
                <strong>Bean</strong>
                <div class="hb-guided-thinking-row">
                    <span>Bean is thinking</span>
                    <span class="hb-guided-thinking-dots" aria-hidden="true">
                        <span></span><span></span><span></span>
                    </span>
                </div>
            </article>`;
    }

    function guidedOnboardingComposerMarkup() {
        const step = state.guidedSignupStep;
        const disabled = guidedSignupInputLocked();
        const hintMap = {
            name: 'Name',
            themeMode: 'Choose Light, Dark, or Auto...',
            email: 'Text your email address...',
            password: 'Text your password...',
            personality: 'Type a personality choice...',
            location: 'Type skip, or tap Allow location...',
            tourChoice: 'Yes for tour, no for plan setup...',
        };
        return `
            <form class="hb-guided-onboarding-composer" data-action="guided-onboarding">
                <div class="hb-guided-onboarding-input-row">
                    <input
                        class="hb-input hb-guided-onboarding-input"
                        name="message"
                        type="${step === 'password' ? 'password' : 'text'}"
                        placeholder="${escapeAttr(hintMap[step] || 'Message Bean...')}"
                        ${disabled ? 'disabled' : ''}
                        autocomplete="off"
                    >
                    <button class="hb-button hb-guided-onboarding-send" type="submit" ${disabled ? 'disabled' : ''} aria-label="Send">↑</button>
                </div>
            </form>`;
    }

    function guidedOnboardingStatusMarkup() {
        const parts = [];
        if (state.guidedSignupStep === 'location') {
            parts.push(`<div class="hb-guided-onboarding-helper">${
                state.busy
                    ? 'Your browser should be asking for location access now. Click Allow in the prompt, or use the location control next to the address bar.'
                    : 'When you tap Allow location, your browser should ask for permission. Click Allow to share your city.'
            }</div>`);
        }
        if (state.guidedSignupError) {
            parts.push(`<div class="hb-guided-onboarding-error">${escapeHtml(state.guidedSignupError)}</div>`);
        }
        return parts.join('');
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

    function guidedPersonalityPanelMarkup() {
        return `
            <section class="hb-guided-choice-panel hb-guided-personality-panel" aria-label="Bean personality">
                ${guidedSignupPersonalities.map((option) => `
                    <button class="hb-guided-personality-card ${state.guidedSignupPersonality === option.key ? 'hb-guided-personality-card-active' : ''}" type="button" data-guided-personality="${escapeAttr(option.key)}" ${guidedSignupInputLocked() ? 'disabled' : ''}>
                        <span>${escapeHtml(option.label)}</span>
                        <small>${escapeHtml(option.description)}</small>
                    </button>
                `).join('')}
            </section>`;
    }

    function guidedLocationPanelMarkup() {
        return `
            <section class="hb-guided-choice-panel hb-guided-choice-panel-row">
                <button class="hb-button" type="button" data-guided-location="allow" ${guidedSignupInputLocked() ? 'disabled' : ''}>Allow location</button>
                <button class="hb-button-secondary" type="button" data-guided-location="skip" ${guidedSignupInputLocked() ? 'disabled' : ''}>Skip</button>
            </section>`;
    }

    function guidedTourChoicePanelMarkup() {
        return `
            <section class="hb-guided-choice-panel hb-guided-choice-panel-row">
                <button class="hb-button" type="button" data-guided-tour-choice="tour" ${guidedSignupInputLocked() ? 'disabled' : ''}>Show me</button>
                <button class="hb-button-secondary" type="button" data-guided-tour-choice="skip" ${guidedSignupInputLocked() ? 'disabled' : ''}>Skip tour</button>
            </section>`;
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
            : 'Your account has been created. Check your email to verify. Next, choose the plan that fits how much of your calendar, tasks, reminders, and daily context you want Bean to handle.';
        return `
            <div class="hb-app">
                <main class="hb-guided-onboarding-shell hb-guided-onboarding-shell-subscribe">
                    <div class="hb-guided-onboarding-topbar">
                        <button class="hb-button-ghost" type="button" data-subscribe-logout>Use a different account</button>
                    </div>
                    <section class="hb-guided-onboarding-stage">
                        <div class="hb-guided-onboarding-thread">
                            <article class="hb-guided-message hb-guided-message-bean">
                                <strong>Bean</strong>
                                <p>${escapeHtml(introMessage)}</p>
                            </article>
                            ${state.notice ? `<div class="hb-success">${escapeHtml(state.notice)}</div>` : ''}
                            ${canceled ? '<div class="hb-error"><strong>Checkout was canceled</strong><span>No charge was made. Choose a plan when you are ready to continue.</span></div>' : ''}
                            ${errorMarkup(state.error)}
                            <section class="hb-guided-choice-panel hb-guided-plan-panel">
                                ${confirmed ? subscriptionConfirmationMarkup(selectedPlan, subscription, liveConfirmed) : subscriptionPlanSelectionMarkup(selectedPlan)}
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
            ['2', 'Bean setup'],
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
            ${couponCodeEntryMarkup('subscribe')}
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
            return `${plan.label} is active. Bean is ready to open your dashboard.`;
        }
        return `Stripe sent you back to HeyBean. ${plan.label} setup is recorded, and Bean will update the live subscription status as soon as Stripe confirms it.`;
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
                    <button class="hb-button" type="button" data-subscribe-dashboard>Go to dashboard</button>
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
                    <div class="hb-topbar-date-line" data-tour-target="calendar-controls">
                        <time class="hb-topbar-current-time" data-current-time datetime="${escapeAttr(now.toISOString())}">${escapeHtml(formatTopbarTime(now))}</time>
                        <button class="hb-header-pill" data-today type="button"><span>${escapeHtml(topbarTodayLabel(now))}</span></button>
                        <button class="hb-header-pill hb-month-pill" data-calendar-month type="button"><span>${escapeHtml(monthLabel(now))}</span></button>
                    </div>
                    ${state.selected === 'today' && state.showMonth ? `<div class="hb-topbar-month-cluster">${monthSwitcherMarkup(parseLocalDate(state.selectedDay))}</div>` : ''}
                    <span class="hb-spacer"></span>
                    ${topNavMarkup()}
                    ${showAdd ? topCreateMenuMarkup() : ''}
                    ${criticalMenuMarkup(criticalTasks, criticalReminders, criticalEvents)}
                    ${topProfileMenuMarkup()}
                </header>
                <main class="hb-main ${state.selected === 'bean' ? 'hb-main-chat' : ''} ${state.selected === 'today' ? 'hb-main-today' : ''} ${['tasks', 'reminders'].includes(state.selected) ? 'hb-main-board' : ''} ${state.selected === 'notes' ? 'hb-main-notes' : ''} ${state.selected === 'memory' ? 'hb-main-memory' : ''} ${state.selected === 'admin' ? 'hb-main-admin' : ''}">
                    ${state.selected === 'bean' ? chatMarkup() : appPanelMarkup()}
                </main>
                ${state.selected === 'bean' ? '' : approvalSheetMarkup()}
                ${bottomMenuMarkup()}
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
        if (state.selected === 'notes') {
            ensureSelectedNote();
            return notesMarkup();
        }
        if (state.selected === 'memory') {
            return memoryMarkup();
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
            <section class="hb-card hb-card-pad hb-board-card" data-tour-target="tasks-view">
                ${sectionTitle(icons.tasks, 'Tasks', completed ? 'Completed tasks' : 'Active tasks')}
                <div class="hb-tabs">
                    <button class="hb-chip" type="button" data-task-filter="active" aria-pressed="${!completed}">Active</button>
                    <button class="hb-chip" type="button" data-task-filter="done" aria-pressed="${completed}">Done</button>
                </div>
                ${state.dashboardDataLoading && !items.length ? dashboardLoadingMarkup('Loading tasks...') : dayBoardMarkup(items, 'task', completed ? 'No completed tasks' : 'No active tasks')}
            </section>`;
    }

    function remindersMarkup() {
        const completed = state.reminderFilter === 'completed';
        const items = state.reminders.filter((reminder) => reminderCompleted(reminder) === completed);
        return `
            <section class="hb-card hb-card-pad hb-board-card" data-tour-target="reminders-view">
                ${sectionTitle(icons.reminders, 'Reminders', completed ? 'Completed reminders' : 'Pending reminders')}
                <div class="hb-tabs">
                    <button class="hb-chip" type="button" data-reminder-filter="pending" aria-pressed="${!completed}">Pending</button>
                    <button class="hb-chip" type="button" data-reminder-filter="completed" aria-pressed="${completed}">Completed</button>
                </div>
                ${state.dashboardDataLoading && !items.length ? dashboardLoadingMarkup('Loading reminders...') : dayBoardMarkup(items, 'reminder', completed ? 'No completed reminders' : 'No pending reminders')}
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
                    ${noteFolderButtonMarkup('unfiled', 'Unfiled', state.notes.filter((note) => !(note.note_folder_id || note.noteFolderId)).length, icons.notes)}
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
                                ${noteListOptionButton('unfiled', 'Unfiled', state.notes.filter((note) => !(note.note_folder_id || note.noteFolderId)).length)}
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
        const syncWorkspaces = workspaces().filter((workspace) => String(workspace.id) !== activeWorkspaceId);
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
                    <span class="hb-note-toolbar-divider"></span>
                    ${noteCommandButton('formatBlock', 'h1', 'H1', locked, 'Heading 1')}
                    ${noteCommandButton('bold', '', 'B', locked, 'Bold')}
                    ${noteCommandButton('italic', '', 'I', locked, 'Italic')}
                    ${noteCommandButton('checkbox', '', '☐', locked, 'Toggle checklist item')}
                    ${noteCommandButton('bullet', '', '•', locked, 'Bulleted line')}
                    ${noteCommandButton('outdent', '', icons.indentDecrease, locked, 'Decrease indent', true)}
                    ${noteCommandButton('indent', '', icons.indentIncrease, locked, 'Increase indent', true)}
                    ${noteCommandButton('insertHorizontalRule', '', '---', locked, 'Divider')}
                    <span class="hb-note-save-state" aria-live="polite">${locked ? 'Locked' : state.notesSaving ? 'Saving...' : 'Auto-saved'}</span>
                    <details class="hb-note-actions-menu">
                        <summary aria-label="Note actions" title="Note actions">${icons.moreVertical}</summary>
                        <div class="hb-note-actions-popover" role="menu">
                            <button type="button" data-toggle-note-pin="${escapeAttr(note.id)}" role="menuitem">${icons.pin}<span>${pinned ? 'Unpin note' : 'Pin note'}</span></button>
                            <details class="hb-note-workspace-menu">
                                <summary>${icons.spaces}<span>Workspaces</span></summary>
                                <div>
                                    <small>Saved in ${escapeHtml(workspaceDisplayName(findWorkspace(activeWorkspaceId)))}.</small>
                                    ${syncWorkspaces.length ? syncWorkspaces.map((workspace) => `<label><input type="checkbox" data-note-sync-workspace="${escapeAttr(workspace.id)}" ${noteWorkspaceIds.has(String(workspace.id)) ? 'checked' : ''}> <span>${escapeHtml(workspaceDisplayName(workspace))}</span></label>`).join('') : '<small>No other workspaces available.</small>'}
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
                <div class="hb-note-body" contenteditable="${locked ? 'false' : 'true'}" data-note-body spellcheck="true" data-note-locked="${locked ? 'true' : 'false'}">${note.body_html || note.bodyHtml || noteTextToHtml(note.plain_text || note.plainText || '')}</div>
                <input type="hidden" name="metadata" value="${escapeAttr(JSON.stringify(metadata))}">
            </form>`;
    }

    function noteCommandButton(command, value, label, disabled = false, title = '', htmlLabel = false) {
        const accessibleTitle = title || label;
        const content = htmlLabel ? label : escapeHtml(label);
        return `<button class="hb-note-format-button" type="button" data-note-command="${escapeAttr(command)}" data-note-command-value="${escapeAttr(value)}" aria-label="${escapeAttr(accessibleTitle)}" title="${escapeAttr(accessibleTitle)}" ${disabled ? 'disabled' : ''}>${content}</button>`;
    }

    function notesEmptyEditorMarkup() {
        return `
            <div class="hb-notes-empty-editor">
                ${icons.notes}
                <strong>Select or create a note</strong>
            </div>`;
    }

    function memoryMarkup() {
        const items = filteredMemoryItems();
        const activeCount = state.memoryItems.filter((item) => (item.status || 'active') === 'active').length;
        const highConfidence = state.memoryItems.filter((item) => Number(item.confidence || 0) >= 85).length;
        const summaries = normalizeList(state.memorySummaries).slice(0, 4);
        const history = normalizeList(state.memoryHistory).slice(0, 10);
        return `
            <section class="hb-memory-app" aria-label="Bean's Knowledge">
                <header class="hb-memory-hero">
                    <div>
                        <span class="hb-memory-kicker">${icons.memory}<span>Bean's Knowledge</span></span>
                        <h1>What Bean knows about this workspace</h1>
                        <p>Review durable facts, preferences, project context, and recent recall without loading your whole history into every response.</p>
                    </div>
                    <div class="hb-memory-stats" aria-label="Knowledge health">
                        <span><strong>${activeCount}</strong><small>active</small></span>
                        <span><strong>${highConfidence}</strong><small>verified</small></span>
                        <span><strong>${summaries.length}</strong><small>summaries</small></span>
                    </div>
                </header>
                <div class="hb-memory-grid">
                    <aside class="hb-memory-side">
                        <form class="hb-memory-new" data-memory-create-form>
                            <strong>Add knowledge</strong>
                            <label>Type
                                <select class="hb-select" name="type">${memoryTypeOptions('fact')}</select>
                            </label>
                            <label>Knowledge
                                <textarea class="hb-textarea" name="content" rows="4" placeholder="Bean should know..."></textarea>
                            </label>
                            <button class="hb-button" type="submit" ${state.memorySaving ? 'disabled' : ''}>${state.memorySaving ? 'Saving...' : 'Save'}</button>
                        </form>
                        <section class="hb-memory-panel">
                            <strong>Recent recall</strong>
                            ${history.length ? history.map(memoryHistoryItemMarkup).join('') : state.dashboardDataLoading ? dashboardLoadingMarkup('Loading recent recall...') : '<div class="hb-empty">No recent requests loaded yet.</div>'}
                        </section>
                    </aside>
                    <main class="hb-memory-main">
                        <div class="hb-memory-toolbar">
                            <input class="hb-input" type="search" data-memory-search placeholder="Search knowledge" value="${escapeAttr(state.memorySearch)}">
                            <select class="hb-select" data-memory-type-filter>
                                <option value="">All types</option>
                                ${memoryTypeOptions(state.memoryTypeFilter)}
                            </select>
                            <button class="hb-button-secondary" type="button" data-refresh-memory>Refresh</button>
                        </div>
                        <div class="hb-memory-list">
                            ${items.length ? items.map(memoryItemMarkup).join('') : state.dashboardDataLoading ? dashboardLoadingMarkup('Loading knowledge...') : '<div class="hb-memory-empty">No matching knowledge yet.</div>'}
                        </div>
                        <section class="hb-memory-summaries">
                            <h2>Summaries</h2>
                            ${summaries.length ? summaries.map(memorySummaryMarkup).join('') : state.dashboardDataLoading ? dashboardLoadingMarkup('Loading summaries...') : '<div class="hb-empty">Summaries will appear as Bean builds history.</div>'}
                        </section>
                    </main>
                </div>
            </section>`;
    }

    function filteredMemoryItems() {
        const search = String(state.memorySearch || '').trim().toLowerCase();
        const type = String(state.memoryTypeFilter || '');
        return normalizeList(state.memoryItems).filter((item) => {
            if (type && String(item.type || '') !== type) return false;
            if (!search) return true;
            return [item.title, item.content, item.summary, item.type].some((value) => String(value || '').toLowerCase().includes(search));
        });
    }

    function memoryItemMarkup(item) {
        const id = String(item.id || '');
        const confidence = Number(item.confidence || 0);
        const importance = Number(item.importance || 0);
        return `
            <article class="hb-memory-card" data-memory-id="${escapeAttr(id)}">
                <div class="hb-memory-card-head">
                    <span class="hb-memory-type">${escapeHtml(memoryTypeLabel(item.type))}</span>
                    <span class="hb-memory-score">${confidence}% · ${importance}</span>
                </div>
                <form data-memory-update-form="${escapeAttr(id)}">
                    <input class="hb-memory-title-input" name="title" value="${escapeAttr(item.title || '')}" placeholder="Optional title">
                    <textarea class="hb-memory-content-input" name="content" rows="3">${escapeHtml(item.content || '')}</textarea>
                    <div class="hb-memory-card-actions">
                        <select class="hb-select" name="type">${memoryTypeOptions(item.type || 'fact')}</select>
                        <button class="hb-button-secondary" type="submit">Save</button>
                        <button class="hb-button-ghost" type="button" data-memory-forget="${escapeAttr(id)}">Forget</button>
                    </div>
                </form>
            </article>`;
    }

    function memorySummaryMarkup(summary) {
        return `
            <article class="hb-memory-summary">
                <strong>${escapeHtml(summary.title || memoryTypeLabel(summary.summary_type || 'summary'))}</strong>
                <p>${escapeHtml(summary.summary || '')}</p>
                <small>${escapeHtml(summary.period_key || formatDateTime(summary.updated_at || summary.updatedAt || ''))}</small>
            </article>`;
    }

    function memoryHistoryItemMarkup(item) {
        return `
            <div class="hb-memory-history-item">
                <span>${escapeHtml(formatDateTime(item.created_at || item.createdAt || ''))}</span>
                <p>${escapeHtml(item.content || '')}</p>
            </div>`;
    }

    function memoryTypeOptions(selected = '') {
        return ['fact', 'preference', 'identity', 'relationship', 'project', 'routine', 'constraint', 'decision', 'instruction', 'temporary_context']
            .map((type) => `<option value="${escapeAttr(type)}" ${String(selected) === type ? 'selected' : ''}>${escapeHtml(memoryTypeLabel(type))}</option>`)
            .join('');
    }

    function memoryTypeLabel(type) {
        return String(type || 'fact').replace(/_/g, ' ').replace(/\b\w/g, (letter) => letter.toUpperCase());
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
        const dailyActivity = normalizeList(usage.daily_activity || usage.dailyActivity);
        return `
            <section class="hb-card hb-card-pad hb-admin-panel">
                <div class="hb-section-action-row">
                    ${sectionTitle(icons.activity, 'Project admin', 'Business, traffic, app usage, server health, and AI operations')}
                    <button class="hb-button-secondary" type="button" data-refresh-admin ${state.adminUsageLoading ? 'disabled' : ''}>${state.adminUsageLoading ? 'Refreshing...' : 'Refresh'}</button>
                </div>
                ${errorMarkup(state.error)}
                ${state.adminUsageLoading && !state.adminUsage ? '<div class="hb-empty hb-surface-soft">Loading AI usage metrics...</div>' : ''}
                ${adminExecutiveKpisMarkup(usage)}
                ${adminHealthGridMarkup(usage)}
                ${adminDailyActivityChartMarkup(dailyActivity)}
                ${adminUserGrowthChartMarkup(userGrowth)}
                <div class="hb-admin-metrics">
                    ${adminMetricMarkup('Users', totals.users, 'Total accounts')}
                    ${adminMetricMarkup('Workspaces', totals.workspaces, 'Total spaces')}
                    ${adminMetricMarkup('Actions today', totals.ai_actions_today, `${formatTokens(totals.tokens_today)} tokens`)}
                    ${adminMetricMarkup('Month cost', formatCurrency(totals.cost_month), `${formatTokens(totals.tokens_month)} tokens`)}
                    ${adminMetricMarkup('Today cost', formatCurrency(totals.cost_today), `${totals.ai_actions_month || 0} actions this month`)}
                    ${adminMetricMarkup('Tools today', totals.tool_calls_today || totals.toolCallsToday || 0, 'External/internal calls')}
                    ${adminMetricMarkup('Open alerts', totals.open_alerts, 'Warnings and hard caps')}
                    ${adminMetricMarkup('Issue reports', totals.open_issue_reports, 'Open beta feedback')}
                </div>
                ${adminHermesMaintenanceMarkup()}
                ${adminSettingsMarkup(settings)}
                ${adminLiveLookupProvidersMarkup(state.adminLiveLookup)}
                ${adminPlanLimitsMarkup(state.adminPlanLimits)}
                ${adminCouponCodesMarkup(state.adminCoupons)}
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

    function adminExecutiveKpisMarkup(usage) {
        const business = usage.business || {};
        const traffic = usage.traffic || {};
        const activation = usage.activation || {};
        const server = usage.server || {};
        return `
            <div class="hb-admin-kpi-grid">
                ${adminKpiMarkup('Daily run-rate', formatCurrency(business.daily_revenue_rate || 0), 'From active local subscriptions', 'money')}
                ${adminKpiMarkup('Weekly run-rate', formatCurrency(business.weekly_revenue_rate || 0), 'From active local subscriptions', 'money')}
                ${adminKpiMarkup('MRR', formatCurrency(business.mrr || 0), `${escapeHtml(business.active_paid_subscriptions || 0)} paid subscriptions`, 'money')}
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

    function adminHealthGridMarkup(usage) {
        return `
            <div class="hb-admin-health-grid">
                ${adminBusinessHealthMarkup(usage.business || {})}
                ${adminTrafficHealthMarkup(usage.traffic || {})}
                ${adminActivationHealthMarkup(usage.activation || {})}
                ${adminAppUsageHealthMarkup(usage.app_usage || usage.appUsage || {})}
                ${adminServerHealthMarkup(usage.server || {})}
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

    function adminAppUsageHealthMarkup(appUsage) {
        const today = appUsage.created_today || appUsage.createdToday || {};
        const month = appUsage.created_month || appUsage.createdMonth || {};
        return `
            <section class="hb-admin-health-card">
                <div class="hb-admin-health-head">
                    <strong>App usage</strong>
                    <mark class="hb-admin-status ${appUsage.success_rate_today === null || appUsage.successRateToday === null ? 'hb-admin-status-warning' : 'hb-admin-status-ok'}">${escapeHtml(formatPercent(appUsage.success_rate_today ?? appUsage.successRateToday))} success today</mark>
                </div>
                <div class="hb-admin-health-metrics">
                    ${adminHealthMetricMarkup('Tasks today', today.tasks || 0)}
                    ${adminHealthMetricMarkup('Reminders today', today.reminders || 0)}
                    ${adminHealthMetricMarkup('Events today', today.calendar_events || today.calendarEvents || 0)}
                    ${adminHealthMetricMarkup('Notes today', today.notes || 0)}
                    ${adminHealthMetricMarkup('Chats month', appUsage.chat_messages_month || appUsage.chatMessagesMonth || 0)}
                    ${adminHealthMetricMarkup('Actions month', appUsage.activity_events_month || appUsage.activityEventsMonth || 0)}
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
            aiActions: Number(point.ai_actions ?? point.aiActions ?? 0),
            signups: Number(point.signups ?? 0),
        }));
        const width = 760;
        const height = 210;
        const padLeft = 44;
        const padRight = 22;
        const padTop = 24;
        const padBottom = 34;
        const max = niceChartMax(Math.max(1, ...values.flatMap((point) => [point.pageViews, point.aiActions, point.signups])));
        const xFor = (index) => values.length <= 1 ? padLeft : padLeft + (index / (values.length - 1)) * (width - padLeft - padRight);
        const yFor = (value) => height - padBottom - (value / max) * (height - padTop - padBottom);
        const pathFor = (key) => values.map((point, index) => `${index === 0 ? 'M' : 'L'} ${xFor(index).toFixed(1)} ${yFor(point[key]).toFixed(1)}`).join(' ');
        const ticks = chartYTicks(max, max <= 5 ? max : 4);
        return `
            <div class="hb-admin-growth-card hb-admin-activity-chart-card">
                <div class="hb-admin-growth-header">
                    <div>
                        <strong>Daily app pulse</strong>
                        <small>Traffic, Bean actions, and signups over the selected range</small>
                    </div>
                    <div class="hb-admin-chart-legend">
                        <span><i class="hb-legend-traffic"></i>Page views</span>
                        <span><i class="hb-legend-actions"></i>AI actions</span>
                        <span><i class="hb-legend-signups"></i>Signups</span>
                    </div>
                </div>
                <svg class="hb-admin-growth-chart" viewBox="0 0 ${width} ${height}" role="img" aria-label="Daily app pulse chart">
                    ${ticks.map((tick) => `
                        <line x1="${padLeft}" y1="${yFor(tick).toFixed(1)}" x2="${width - padRight}" y2="${yFor(tick).toFixed(1)}" class="${tick === 0 ? 'hb-admin-growth-axis' : 'hb-admin-growth-grid'}"></line>
                        <text x="${padLeft - 10}" y="${(yFor(tick) + 4).toFixed(1)}" text-anchor="end" class="hb-admin-growth-label">${escapeHtml(formatCompactNumber(tick))}</text>
                    `).join('')}
                    <path d="${escapeAttr(pathFor('pageViews'))}" class="hb-admin-activity-line hb-admin-activity-traffic"></path>
                    <path d="${escapeAttr(pathFor('aiActions'))}" class="hb-admin-activity-line hb-admin-activity-actions"></path>
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
                    ${adminModelSelectMarkup('external_lookup_model', models.external_lookup_model || models.externalLookupModel)}
                </div>
                <div class="hb-admin-settings-grid hb-admin-kill-grid">
                    ${adminSwitchMarkup('bean_chat_enabled', 'Bean chat enabled', 'Pause all Bean text/background requests immediately.', settingValue(killSwitches.bean_chat_enabled || killSwitches.beanChatEnabled) !== false)}
                </div>
                <label class="hb-admin-apply-row">
                    <input type="checkbox" name="apply_main_model_to_profiles">
                    <span>Apply main model to existing workspace Bean profiles</span>
                </label>
            </form>`;
    }

    function adminLiveLookupProvidersMarkup(liveLookup) {
        const providers = normalizeList(liveLookup?.providers);
        const cacheSeconds = Number(liveLookup?.cache_seconds ?? liveLookup?.cacheSeconds ?? 0);
        return `
            <section class="hb-admin-settings hb-admin-live-lookup">
                <div class="hb-section-action-row">
                    <div>
                        <strong>Live lookup providers</strong>
                        <small>Connected external data sources, monthly usage, and response speed</small>
                    </div>
                    <span class="hb-item-meta">${cacheSeconds > 0 ? `Cache ${Math.round(cacheSeconds / 60)} min` : 'No cache'}</span>
                </div>
                <div class="hb-admin-provider-grid">
                    ${providers.length ? providers.map(adminLiveLookupProviderMarkup).join('') : '<div class="hb-empty">No live lookup providers configured yet.</div>'}
                </div>
            </section>`;
    }

    function adminLiveLookupProviderMarkup(provider) {
        const usage = provider.usage || {};
        const connected = provider.connected === true;
        const configured = provider.configured !== false;
        const statusLabel = connected ? 'Connected' : configured ? 'Configured' : 'Needs setup';
        const statusClass = connected ? 'hb-admin-status-ok' : configured ? 'hb-admin-status-warning' : 'hb-admin-status-danger';
        const latency = usage.avg_latency_ms ?? usage.avgLatencyMs;
        const lastUsed = usage.last_used_at || usage.lastUsedAt;
        return `
            <article class="hb-admin-provider-card">
                <div class="hb-admin-provider-head">
                    <div>
                        <strong>${escapeHtml(provider.label || provider.key || 'Provider')}</strong>
                        <small>${escapeHtml(`${provider.category || 'External'} · ${provider.mode || 'API'}`)}</small>
                    </div>
                    <mark class="hb-admin-status ${statusClass}">${escapeHtml(statusLabel)}</mark>
                </div>
                <p>${escapeHtml(provider.notes || '')}</p>
                <div class="hb-admin-provider-stats">
                    <span><strong>${escapeHtml(usage.requests ?? 0)}</strong><small>Requests</small></span>
                    <span><strong>${escapeHtml(usage.completed ?? 0)}</strong><small>Completed</small></span>
                    <span><strong>${escapeHtml((usage.failed ?? 0) + (usage.blocked ?? 0))}</strong><small>Failed/blocked</small></span>
                    <span><strong>${escapeHtml(latency ? `${latency}ms` : '—')}</strong><small>Avg latency</small></span>
                    <span><strong>${escapeHtml(formatCurrency(usage.cost || 0))}</strong><small>Cost</small></span>
                    <span><strong>${escapeHtml(provider.timeout_ms || provider.timeoutMs ? `${provider.timeout_ms || provider.timeoutMs}ms` : '—')}</strong><small>Timeout</small></span>
                </div>
                <div class="hb-admin-provider-foot">
                    <span>${escapeHtml(provider.key || '')}</span>
                    <span>${escapeHtml(lastUsed ? `Last used ${formatDateTime(lastUsed)}` : 'No usage this month')}</span>
                </div>
            </article>`;
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
                    <button class="hb-button-secondary" type="submit" ${state.adminUsageLoading ? 'disabled' : ''}>Create code</button>
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
                <button class="hb-admin-mini-action" type="button" data-admin-coupon-delete="${escapeAttr(coupon.id)}" ${state.adminUsageLoading ? 'disabled' : ''}>Delete</button>
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
            <label><span>Note limit</span><input class="hb-input" type="number" min="0" name="note_limit" placeholder="Unlimited" value="${escapeAttr(limitInputValue(limits.note_limit ?? limits.noteLimit))}"></label>
            <div class="hb-admin-kill-grid">
                ${adminSwitchMarkup('recurring_tasks_enabled', 'Recurring tasks', 'Allow recurring tasks for this tier/customer.', Boolean(limits.recurring_tasks_enabled ?? limits.recurringTasksEnabled))}
                ${adminSwitchMarkup('recurring_reminders_enabled', 'Recurring reminders', 'Allow recurring reminders for this tier/customer.', Boolean(limits.recurring_reminders_enabled ?? limits.recurringRemindersEnabled))}
                ${adminSwitchMarkup('recurring_calendar_enabled', 'Recurring calendar events', 'Allow recurring calendar event series.', Boolean(limits.recurring_calendar_enabled ?? limits.recurringCalendarEnabled))}
                ${adminSwitchMarkup('email_reminders_enabled', 'Email reminders', 'Allow reminder delivery by email.', Boolean(limits.email_reminders_enabled ?? limits.emailRemindersEnabled))}
                ${adminSwitchMarkup('notes_enabled', 'Notes', 'Allow Notes and note folders.', Boolean(limits.notes_enabled ?? limits.notesEnabled))}
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

    function beanWorkListMarkup(items, className = 'hb-bean-work-list', progressLabel = '') {
        return `
            <ul class="${escapeAttr(className)}" aria-label="Bean work queue" ${items.length ? '' : 'aria-hidden="true"'}>
                ${items.map((item, index) => beanWorkItemMarkup(item, index === 0 ? progressLabel : '')).join('')}
            </ul>`;
    }

    function beanWorkItemMarkup(item, progressLabel = '') {
        const done = beanWorkItemDone(item);
        return `
            <li class="hb-bean-work-item ${done ? 'hb-bean-work-item-done' : ''}" data-bean-work-id="${escapeAttr(item.id || '')}">
                <span class="hb-bean-work-checkbox" data-bean-work-checkbox aria-hidden="true">${done ? icons.checkCircle : ''}</span>
                <span data-bean-work-label>${escapeHtml(item.label || 'Bean work item')}</span>
                ${progressLabel ? `<span class="hb-bean-work-progress">${escapeHtml(progressLabel)}</span>` : ''}
            </li>`;
    }

    function beanWorkStatusActive() {
        if (Date.now() < beanWorkStatusHoldUntil && state.beanWorkItems.length > 0) return true;
        return state.busy
            || (state.chatRunState !== 'Ready' && state.beanWorkItems.length > 0)
            || state.beanWorkItems.some((item) => !beanWorkItemDone(item));
    }

    function beanWorkDisplayItems() {
        let items = state.beanWorkItems.filter((item) => item?.label);
        if (items.some((item) => String(item?.source || '') === 'event' || item?.resolvedByEvent)) {
            items = items.filter((item) => {
                const id = String(item?.id || '');
                return !id.startsWith('request-');
            });
        }
        if (items.length) return items.slice(-6);
        return [];
    }

    function beanWorkStatusLabel(items = beanWorkDisplayItems()) {
        if (items.length && items.every((item) => beanWorkItemDone(item))) return 'Done';
        if (items.some((item) => !beanWorkItemDone(item))) return 'Working...';
        const current = items.find((item) => !beanWorkItemDone(item));
        if (current) return current.label;
        return state.chatRunState && state.chatRunState !== 'Ready' ? state.chatRunState : 'Bean is ready';
    }

    function beanWorkItemDone(item) {
        return ['completed', 'succeeded', 'recorded', 'cancelled', 'failed', 'skipped'].includes(String(item?.status || '').toLowerCase());
    }

    function resetBeanWorkItems(labels, status = 'running') {
        cancelBeanWorkStatusClear();
        stopBeanWorkEventPolling();
        beanWorkEventFloorId = maxActivityEventId(state.activity);
        beanWorkAppliedEventIds.clear();
        beanDashboardRefreshEventIds.clear();
        const normalizedLabels = normalizeList(Array.isArray(labels) ? labels : (labels ? [labels] : []))
            .map((label) => String(label || '').trim())
            .filter((label) => label && !isGenericBeanWorkLabel(label))
            .slice(0, 6);
        if (!normalizedLabels.length) {
            state.beanWorkItems = [];
            refreshBeanStatusTag();
            return;
        }
        state.beanWorkItems = normalizedLabels.map((label, index) => ({ id: `request-${index}`, label, status }));
        refreshBeanStatusTag();
    }

    function upsertBeanWorkItem(id, label, status = 'running', options = {}) {
        if (!id || !label) return;
        const normalizedStatus = String(status || 'running').toLowerCase();
        if (!beanWorkItemDone({ status: normalizedStatus })) {
            cancelBeanWorkStatusClear();
            beanWorkStatusMinUntil = Math.max(beanWorkStatusMinUntil, Date.now() + 700);
        }
        if (options.source === 'event') {
            removeLocalBeanWorkPlaceholders();
        }
        const existingIndex = state.beanWorkItems.findIndex((item) => item.id === id);
        const next = {
            id,
            label,
            status: normalizedStatus,
            ...(Number.isFinite(Number(options.order)) ? { order: Number(options.order) } : {}),
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
        state.beanWorkItems = [...state.beanWorkItems, next]
            .sort((left, right) => Number(left.order ?? 999) - Number(right.order ?? 999))
            .slice(-8);
        if (state.beanWorkItems.every((item) => beanWorkItemDone(item))) scheduleBeanWorkStatusClear();
        refreshBeanStatusTag();
    }

    function removeLocalBeanWorkPlaceholders() {
        const next = state.beanWorkItems.filter((item) => {
            const id = String(item?.id || '');
            return !id.startsWith('request-');
        });
        if (next.length !== state.beanWorkItems.length) {
            state.beanWorkItems = next;
        }
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
        if (state.busy) return;
        window.clearTimeout(beanWorkStatusClearTimer);
        const delay = Math.max(delayMs, beanWorkStatusMinUntil - Date.now(), 0);
        beanWorkStatusHoldUntil = Date.now() + delay;
        beanWorkStatusClearTimer = window.setTimeout(() => {
            beanWorkStatusClearTimer = 0;
            if (state.busy) {
                scheduleBeanWorkStatusClear(delayMs);
                return;
            }
            beanWorkStatusHoldUntil = 0;
            beanWorkStatusMinUntil = 0;
            state.beanWorkItems = [];
            state.activeBeanWorkMessageId = null;
            refreshBeanStatusTag();
        }, delay);
    }

    function cancelBeanWorkStatusClear() {
        window.clearTimeout(beanWorkStatusClearTimer);
        beanWorkStatusClearTimer = 0;
        beanWorkStatusHoldUntil = 0;
    }

    function prepareBeanWorkForFreshRequest() {
        window.clearTimeout(beanWorkStatusClearTimer);
        beanWorkStatusClearTimer = 0;
        beanWorkStatusHoldUntil = 0;
        beanWorkStatusMinUntil = 0;
        beanWorkEventFloorId = maxActivityEventId(state.activity);
        beanWorkAppliedEventIds.clear();
        beanDashboardRefreshEventIds.clear();
        state.activeBeanWorkMessageId = null;
        state.beanWorkItems = [];
        refreshBeanStatusTag();
    }

    function ensurePendingBeanWorkItem() {
        if (state.beanWorkItems.some((item) => !beanWorkItemDone(item))) return;
        resetBeanWorkItems(['Working on request']);
    }

    function maxActivityEventId(events = []) {
        return normalizeList(events).reduce((maxId, event) => {
            const eventId = Number(event?.id || 0);
            return Number.isFinite(eventId) ? Math.max(maxId, eventId) : maxId;
        }, 0);
    }

    function refreshBeanStatusTag() {
        if (state.phase !== 'signedIn') return;
        refreshChatWorkStripInPlace();
    }

    function beanWorkPlaceholderIndex(label) {
        const target = beanWorkTargetForLabel(label);
        const subjectKey = beanWorkSubjectKeyForLabel(label);
        return state.beanWorkItems.findIndex((item) => {
            if (!String(item.id || '').startsWith('request-') || item.resolvedByEvent || beanWorkItemDone(item)) return false;
            const placeholderTarget = beanWorkTargetForLabel(item.label);
            const placeholderSubjectKey = beanWorkSubjectKeyForLabel(item.label);
            if (target && placeholderTarget && target !== placeholderTarget) return false;
            if (subjectKey && placeholderSubjectKey) {
                return subjectKey === placeholderSubjectKey
                    || subjectKey.includes(placeholderSubjectKey)
                    || placeholderSubjectKey.includes(subjectKey);
            }
            if (!subjectKey && placeholderSubjectKey) return false;
            return !target || !placeholderTarget || target === placeholderTarget;
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
            : /\b(?:note|notes|folder)\b/.test(text) ? 'note'
            : /\b(?:memory)\b/.test(text) ? 'memory'
            : '';
        return action || target ? `${action}:${target}` : '';
    }

    function beanWorkTargetForLabel(label) {
        const category = beanWorkCategoryForLabel(label);
        const separator = category.indexOf(':');
        return separator >= 0 ? category.slice(separator + 1) : '';
    }

    function beanWorkSubjectKeyForLabel(label) {
        const separator = String(label || '').indexOf(':');
        if (separator < 0) return '';
        let subject = String(label || '').slice(separator + 1).toLowerCase();
        subject = subject
            .replace(/\([^)]*\)/g, ' ')
            .replace(/\b(?:calendar|event|events|task|tasks|reminder|reminders|note|notes|create|creating|created|update|updating|updated|delete|deleting|deleted|save|saving|saved)\b/g, ' ')
            .replace(/[^a-z0-9]+/g, ' ')
            .replace(/\s+/g, ' ')
            .trim();
        if (subject === 'groceries' || subject === 'grocery store') subject = 'grocery shopping';
        if (subject === 'cooking dinner' || subject === 'make dinner') subject = 'cook dinner';
        return subject;
    }

    function isGenericBeanWorkLabel(label) {
        return /^(?:finish|finished|background work|finish background work|bean started working|read request|working on request)$/i.test(String(label || '').trim());
    }

    function applyBeanWorkEvents(events = []) {
        const mutationEvents = [];
        normalizeList(events).sort((left, right) => Number(left?.id || 0) - Number(right?.id || 0)).forEach((event) => {
            const eventId = Number(event?.id || 0);
            if (Number.isFinite(eventId) && eventId <= beanWorkEventFloorId) return;
            if (Number.isFinite(eventId) && beanWorkAppliedEventIds.has(eventId)) return;
            const item = beanWorkItemFromEvent(event);
            if (beanActivityEventMutatesDashboard(event)) mutationEvents.push(event);
            if (!item) return;
            if (!beanWorkEventBelongsToActiveRequest(event, item)) return;
            if (Number.isFinite(eventId)) {
                beanWorkAppliedEventIds.add(eventId);
                beanWorkEventFloorId = Math.max(beanWorkEventFloorId, eventId);
            }
            upsertBeanWorkItem(item.id, item.label, item.status, {
                source: 'event',
                resolvedByEvent: true,
                order: item.order,
            });
        });
        refreshDashboardAfterBeanMutationEvents(mutationEvents);
    }

    function beanWorkEventBelongsToActiveRequest(event, item = null) {
        const activeMessageId = Number(state.activeBeanWorkMessageId || 0);
        if (!activeMessageId) return true;
        const payload = event?.payload || {};
        const eventMessageId = Number(payload.user_message_id || payload.userMessageId || payload.message_id || payload.messageId || payload.request_message_id || payload.requestMessageId || 0);
        if (eventMessageId) return eventMessageId === activeMessageId;
        const type = String(event?.event_type || event?.eventType || '');
        if (['runtime.run_queued', 'runtime.run_started', 'runtime.run_completed', 'runtime.run_stale_failed', 'runtime.run_failed'].includes(type)) return true;
        if (!type.startsWith('assistant.')) return true;
        if (type === 'assistant.work_item.planned' && state.beanWorkItems.length) return true;
        if (!item) return false;
        return state.beanWorkItems.some((existing) => existing.id === item.id)
            || beanWorkPlaceholderIndex(item.label) >= 0;
    }

    function beanActivityEventMutatesDashboard(event) {
        const type = String(event?.event_type || event?.eventType || '');
        if (!type.startsWith('assistant.')) return false;
        if (!beanWorkItemDone({ status: beanWorkEventStatus(String(event?.status || '')) })) return false;
        return /\.(?:task|reminder|calendar_event|note|note_folder|memory|approval|blocker)\.(?:created|updated|deleted)$/.test(type);
    }

    function refreshDashboardAfterBeanMutationEvents(events = []) {
        const freshEvents = freshBeanDashboardMutationEvents(events);
        if (!freshEvents.length) return;
        refreshDashboardResourcesAfterBeanMutations(freshEvents).catch(() => {});
        refreshRealtimeDashboardContext('bean_mutation_event').catch(() => {});
    }

    function freshBeanDashboardMutationEvents(events = []) {
        const fresh = [];
        normalizeList(events).forEach((event) => {
            const eventId = Number(event?.id || 0);
            const key = Number.isFinite(eventId) && eventId > 0
                ? String(eventId)
                : `${event?.event_type || event?.eventType || ''}:${JSON.stringify(event?.payload || {}).slice(0, 160)}`;
            if (beanDashboardRefreshEventIds.has(key)) return;
            if (!beanActivityEventMutatesDashboard(event)) return;
            beanDashboardRefreshEventIds.add(key);
            fresh.push(event);
        });
        return fresh;
    }

    function beanMutationTargets(events = []) {
        const targets = new Set();
        normalizeList(events).forEach((event) => {
            const type = String(event?.event_type || event?.eventType || '');
            if (type.includes('.task.')) targets.add('tasks');
            if (type.includes('.reminder.')) targets.add('reminders');
            if (type.includes('.calendar_event.')) targets.add('calendar');
            if (type.includes('.note_folder.')) targets.add('noteFolders');
            if (type.includes('.note.')) targets.add('notes');
            if (type.includes('.memory.')) targets.add('memory');
            if (type.includes('.approval.') || type.includes('.blocker.')) targets.add('summary');
        });
        return targets;
    }

    async function refreshDashboardResourcesAfterBeanMutations(events = []) {
        const targets = beanMutationTargets(events);
        if (!targets.size) return;
        const workspaceId = currentWorkspaceId();
        const updates = [];
        if (targets.has('tasks')) {
            updates.push(Promise.all([
                api(workspaceScopedPath('/tasks', workspaceId)),
                api(workspaceScopedPath('/tasks/past', workspaceId)).catch(() => []),
            ]).then(([tasks, pastTasks]) => {
                state.tasks = reconcileTaskRefresh(mergeById(normalizeList(tasks), normalizeList(pastTasks)));
            }));
        }
        if (targets.has('reminders')) {
            updates.push(api(workspaceScopedPath('/reminders', workspaceId)).then((reminders) => {
                state.reminders = reconcileReminderRefresh(reminders);
            }));
        }
        if (targets.has('calendar')) {
            updates.push(api(workspaceScopedPath('/calendar-events?skip_google_sync=1&skip_outlook_sync=1', workspaceId)).then((calendar) => {
                state.calendar = reconcileCalendarRefresh(calendar);
            }));
        }
        if (targets.has('notes') || targets.has('noteFolders')) {
            if (targets.has('noteFolders')) {
                updates.push(api(workspaceScopedPath('/note-folders', workspaceId)).then((folders) => {
                    state.noteFolders = normalizeNoteFolders(folders);
                }));
            }
            updates.push(api(workspaceScopedPath('/notes', workspaceId)).then((notes) => {
                state.notes = normalizeNotes(notes);
                ensureSelectedNote();
            }));
        }
        if (targets.has('memory')) {
            updates.push(Promise.all([
                api(workspaceScopedPath('/memory-items', workspaceId)),
                api(workspaceScopedPath('/memory-summaries', workspaceId)),
                api(workspaceScopedPath('/memory/request-history?limit=10', workspaceId)),
            ]).then(([items, summaries, history]) => {
                state.memoryItems = normalizeList(items);
                state.memorySummaries = normalizeList(summaries);
                state.memoryHistory = normalizeList(history);
            }));
        }
        if (targets.has('summary')) {
            updates.push(api(workspaceScopedPath('/today', workspaceId)).then((summary) => {
                state.summary = summary;
                state.approvals = normalizeList(summary?.approvals);
                state.blockers = normalizeList(summary?.blockers);
            }));
        }
        await Promise.all(updates);
        saveDashboardCache(workspaceId);
        renderDashboardDataUpdate({ deferIfEditing: false });
    }

    function beanWorkItemFromEvent(event) {
        const type = String(event?.event_type || event?.eventType || '');
        const status = String(event?.status || '').toLowerCase();
        const payload = event?.payload || {};
        const fallbackId = event?.id ? `event-${event.id}` : `${type}-${JSON.stringify(payload).slice(0, 80)}`;
        if (!type || type === 'runtime.run_queued') return null;
        if (type === 'runtime.run_started' || type === 'runtime.run_completed') return null;
        if (type === 'runtime.run_failed') return { id: fallbackId, label: 'Finish request', status: 'failed' };
        if (!type.startsWith('assistant.')) return null;
        if (type.includes('.duplicate_skipped')) return null;
        const plannedId = payload.work_item_id || payload.workItemId || '';
        const plannedLabel = payload.work_label || payload.workLabel || payload.label || '';
        if (type === 'assistant.work_item.planned' && plannedId && plannedLabel) {
            return {
                id: String(plannedId),
                label: String(plannedLabel),
                status: 'running',
                order: Number(payload.work_order ?? payload.workOrder ?? 0),
            };
        }
        const id = plannedId
            ? String(plannedId)
            : fallbackId;
        const label = beanWorkEventLabel(type, payload);
        if (!label) return null;
        return {
            id,
            label,
            status: beanWorkEventStatus(status),
            order: Number(payload.work_order ?? payload.workOrder ?? 999),
        };
    }

    function beanWorkEventStatus(status) {
        if (['failed', 'skipped', 'cancelled', 'succeeded', 'recorded', 'completed'].includes(status)) return status;
        return 'completed';
    }

    function beanWorkEventLabel(type, payload = {}) {
        const workLabel = payload.work_label || payload.workLabel;
        if (workLabel) return String(workLabel);
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
        if (type.includes('.note.created')) return `Create note${readable}`;
        if (type.includes('.note.updated')) return `Update note${readable}`;
        if (type.includes('.note.deleted')) return `Delete note${readable}`;
        if (type.includes('.note_folder.created')) return `Create folder${readable}`;
        if (type.includes('.note_folder.updated')) return `Update folder${readable}`;
        if (type.includes('.note_folder.deleted')) return `Delete folder${readable}`;
        if (type.includes('.memory.created')) return `Save knowledge${readable}`;
        if (type.includes('.memory.updated')) return `Update knowledge${readable}`;
        if (type.includes('.memory.deleted')) return `Forget knowledge${readable}`;
        if (type.includes('.approval.created')) return `Prepare approval${readable}`;
        if (type.includes('.blocker.created')) return `Flag blocker${readable}`;
        if (type.includes('.workspace_memory.noted')) return 'Save knowledge';
        if (type.includes('.google_calendar.')) return 'Sync Google Calendar';
        return null;
    }

    function startBeanWorkEventPolling(sessionId, options = {}) {
        stopBeanWorkEventPolling();
        const token = ++beanWorkEventPollToken;
        const clientRequestId = String(options.clientRequestId || '').trim();
        const content = String(options.content || '');
        const shouldPlayVoiceResponse = Boolean(options.voiceRequest);
        const poll = async (attempt = 0) => {
            if (!sessionId || token !== beanWorkEventPollToken) return;
            try {
                const after = Math.max(maxActivityEventId(state.activity), beanWorkEventFloorId);
                const events = await api(`/assistant/sessions/${sessionId}/events?after=${encodeURIComponent(after)}&wait=1&limit=50`);
                if (token !== beanWorkEventPollToken) return;
                applyBeanWorkEvents(events);
                if (clientRequestId) {
                    const recovered = await recoverChatFailureFromServer({
                        sessionId,
                        clientRequestId,
                        content,
                    });
                    if (token !== beanWorkEventPollToken) return;
                    if (recovered && (recovered.assistantContent || !['queued', 'running', 'processing'].includes(String(recovered.result?.status || '').toLowerCase()))) {
                        const recoveredAssistant = recovered.result?.assistant_message || null;
                        if (shouldPlayVoiceResponse && recovered.assistantContent) {
                            removeDeferredAssistantMessage(recoveredAssistant);
                            state.chatRunState = 'Bean is speaking…';
                            render();
                            try {
                                pauseVoiceWakeRecognitionForBean();
                                await playBeanVoiceResponse(recovered.assistantContent);
                            } catch (error) {
                                console.warn('Bean voice playback failed.', error);
                            }
                            revealDeferredAssistantMessage(recoveredAssistant, recovered.assistantContent);
                            if (state.voiceWakeListening) {
                                setVoiceFollowUpWindow();
                                state.chatRunState = 'Listening for follow-up…';
                            }
                        }
                        if (state.beanWorkItems.length && state.beanWorkItems.every((item) => beanWorkItemDone(item))) {
                            scheduleBeanWorkStatusClear();
                        }
                        if (!['queued', 'running', 'processing'].includes(String(recovered.result?.status || '').toLowerCase())) {
                            refreshOnlyInBackground({ skipCalendarSync: true });
                        }
                        state.busy = false;
                        activeChatRequestId = 0;
                        render();
                        scrollChatToBottom();
                        scheduleChatQueueDrain();
                        return;
                    }
                }
                render();
            } catch (_) {}
            if (token !== beanWorkEventPollToken) return;
            if ((clientRequestId || beanWorkStatusActive() || attempt < 3) && attempt < 50) {
                const delay = clientRequestId || beanWorkStatusActive() ? 350 : 900;
                beanWorkEventPollTimer = window.setTimeout(() => poll(attempt + 1), delay);
            } else if (clientRequestId && activeChatRequestId) {
                state.busy = false;
                activeChatRequestId = 0;
                render();
                scheduleChatQueueDrain();
            }
        };
        beanWorkEventPollTimer = window.setTimeout(() => poll(0), 250);
    }

    function stopBeanWorkEventPolling() {
        window.clearTimeout(beanWorkEventPollTimer);
        beanWorkEventPollTimer = 0;
        beanWorkEventPollToken += 1;
    }

    function chatMarkup(options = {}) {
        const working = state.busy && state.chatRunState !== 'Ready';
        const waking = beanChatWaking();
        const messages = (state.messages.length ? state.messages : [
            { id: 'intro', role: 'assistant', content: waking ? 'Bean is waking up...' : (needsBeanOnboarding() ? onboardingIntroMessage() : 'Hey! How can I help?') },
        ]).filter((message) => !assistantMessageShouldStayOutOfChat(message));
        const workStrip = chatDockedWorkStripMarkup();
        const messageListId = options.messageListId || 'hb-chat-messages';
        const inputValue = chatInputValue();
        const inputDisabled = waking;
        const sendDisabled = inputDisabled ? 'disabled aria-disabled="true"' : '';
        return `
            <section class="hb-chat ${options.compact ? 'hb-chat-compact' : ''}">
                ${errorMarkup(state.error)}
                <div class="hb-chat-messages" id="${escapeAttr(messageListId)}">
                    ${onboardingInterviewIntroMarkup()}
                    ${messages.map((message, index) => messageMarkup(message, index, messages)).join('')}
                    ${working ? '' : pendingApprovalChatMarkup()}
                    ${working ? '' : onboardingCompletionMarkup()}
                </div>
                <div class="hb-chat-input-stack ${workStrip ? 'hb-chat-input-stack-working' : ''}">
                    ${workStrip}
                    <form class="hb-chat-dock ${workStrip ? 'hb-chat-dock-with-work' : ''}" data-action="chat">
                        <textarea name="message" placeholder="${escapeAttr(chatInputPlaceholder())}" rows="1" ${inputDisabled ? 'disabled aria-disabled="true"' : ''}>${escapeHtml(inputValue)}</textarea>
                        <span class="hb-chat-action-cluster">
                            ${state.busy ? `<button class="hb-button-secondary hb-chat-text-send-button hb-chat-text-stop-button" type="button" data-cancel-turn aria-label="Stop Bean">${icons.stop}</button>` : ''}
                            <button class="hb-button-secondary hb-chat-text-send-button" type="submit" aria-label="${escapeAttr(waking ? 'Bean is waking up' : (state.busy ? 'Queue message' : 'Send message'))}" ${sendDisabled}>${icons.send}</button>
                            <button class="hb-button-secondary hb-chat-text-send-button hb-chat-voice-button ${state.voiceWakeListening ? 'hb-chat-voice-button-listening' : ''} ${(state.busy || state.voiceProcessing) ? 'hb-chat-voice-button-thinking' : ''}" type="button" data-voice-toggle aria-pressed="${state.voiceWakeListening ? 'true' : 'false'}" aria-label="${state.voiceWakeListening ? 'Turn off realtime Bean voice' : 'Turn on realtime Bean voice'}" ${sendDisabled}><span class="hb-chat-voice-button-inner"><img src="${escapeAttr(logoUrl)}" alt="" aria-hidden="true"></span></button>
                        </span>
                    </form>
                </div>
            </section>`;
    }

    function beanChatWaking() {
        return Boolean(state.chatSessionHydrating && !needsBeanOnboarding() && !state.session?.id && !state.busy);
    }

    function chatInputValue() {
        return state.chatDraft || '' || '';
    }

    function chatInputPlaceholder() {
        return 'Message Bean…';
    }

    function chatDockedWorkStripMarkup() {
        const items = beanWorkDisplayItems();
        const active = beanWorkStatusActive() || (Date.now() < beanWorkStatusHoldUntil && items.length > 0);
        if (!active || !items.length) return '';
        const completedCount = items.filter((item) => beanWorkItemDone(item)).length;
        const progressLabel = `${completedCount}/${items.length}`;
        return `
            <section class="hb-chat-work-strip" aria-live="polite">
                ${beanWorkListMarkup(items, 'hb-bean-work-list hb-chat-work-list', progressLabel)}
            </section>`;
    }

    function refreshChatWorkStripInPlace() {
        const stacks = mount.querySelectorAll('.hb-chat-input-stack');
        if (!stacks.length) return;
        const markup = chatDockedWorkStripMarkup();
        stacks.forEach((stack) => {
            const existing = stack.querySelector('.hb-chat-work-strip');
            if (markup) {
                if (existing) {
                    existing.outerHTML = markup;
                } else {
                    stack.insertAdjacentHTML('afterbegin', markup);
                }
            } else if (existing) {
                existing.remove();
            }
            stack.classList.toggle('hb-chat-input-stack-working', Boolean(markup));
            const dock = stack.querySelector('.hb-chat-dock');
            if (dock) dock.classList.toggle('hb-chat-dock-with-work', Boolean(markup));
        });
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
            key: 'command-center-chat',
            title: 'Command center',
            caption: "This is your command center. I'm always here to help, just tell me what you need.",
            view: 'today',
            selectors: ['[data-tour-target="command-center-chat"]'],
        },
        {
            key: 'command-center-agenda',
            title: 'Today at a glance',
            caption: "Above the chat, you'll see today's events, tasks, and reminders in one running list.",
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
            caption: 'Tasks are for things you need to complete. Bean can create them from a sentence, and you can check them off when done.',
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
        if (needsBeanOnboarding() || onboardingTourSeen()) return;
        activateOnboardingTourStep(0);
    }

    function closeOnboardingTour(options = {}) {
        const openCalendarImport = options.openCalendarImport === true && !state.onboardingTourPendingSubscription;
        markOnboardingTourSeen();
        state.onboardingTourActive = false;
        state.onboardingTourStep = 0;
        if (state.onboardingTourPendingSubscription) {
            state.onboardingTourPendingSubscription = false;
            state.phase = 'subscription';
            state.selected = 'today';
            history.pushState({}, '', `/subscribe?plan=${encodeURIComponent(state.selectedPlan || 'premium')}&billing_interval=${encodeURIComponent(normalizedBillingInterval(state.selectedBillingInterval))}`);
        } else if (openCalendarImport) {
            state.selected = 'settings';
            state.modal = { type: 'external-calendar-import', providerKey: 'apple' };
        }
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
                        <button class="hb-button" type="button" ${isLast ? 'data-onboarding-tour-finish' : 'data-onboarding-tour-next'}>${isLast ? (state.onboardingTourPendingSubscription ? 'Plan setup' : 'Import calendar') : 'Next'}</button>
                    </div>
                </article>
            </section>`;
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
                ${sectionTitle(icons.settings, 'Settings')}
                <div class="hb-settings-email-row">
                    <span class="hb-settings-email-icon" aria-hidden="true">${icons.mail}</span>
                    <span class="hb-settings-email-text">${escapeHtml(user.email || '')}</span>
                    <button class="hb-button-ghost hb-settings-email-action" type="button" data-open-profile>Edit</button>
                </div>
                ${errorMarkup(state.error)}
                ${state.notice ? `<div class="hb-success">${escapeHtml(state.notice)}</div>` : ''}
                <div class="hb-compact-item hb-settings-profile-card">
                    <span class="hb-compact-icon">${icons.tune}</span>
                    <div><strong>Bean preferences</strong><small>${escapeHtml(personalityLabel(profilePersonality(profile)))} • ${escapeHtml(priorities.length ? priorities.join(', ') : 'No priorities selected yet')}${context ? ` • ${escapeHtml(context)}` : ''}${complete ? '' : ' • Onboarding not finished'}</small></div>
                    <button class="hb-button-ghost" type="button" data-open-agent>Update</button>
                </div>
                <form class="hb-surface-soft hb-card-pad hb-settings-section hb-settings-location-card hb-home-city-settings" data-home-city-form>
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
                <div class="hb-surface-soft hb-card-pad hb-settings-section hb-settings-notifications-card">
                    ${settingsSectionHeader(icons.bell, 'Notifications', 'Choose how reminders can reach you.')}
                    <label class="hb-switch-row"><input type="checkbox" data-pref="reminder_push" ${prefs.reminder_push !== false ? 'checked' : ''}> Reminder push notifications</label>
                    <label class="hb-switch-row"><input type="checkbox" data-pref="reminder_email" ${prefs.reminder_email === true ? 'checked' : ''}> Reminder emails</label>
                </div>
                <div class="hb-surface-soft hb-card-pad hb-settings-section hb-settings-workspaces-card">
                    <div class="hb-settings-header-with-action">
                        ${settingsSectionHeader(icons.spaces, 'Workspaces', 'Personal and shared spaces with their own Bean, calendar, tasks, reminders, and settings.')}
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
        const chatPercent = commandCenterChatPercent();
        return `
            <section class="hb-card hb-command-center-card ${state.commandCenterChatCollapsed ? 'hb-command-center-card-collapsed' : ''}" aria-label="Bean command center" data-command-center-shell style="--hb-command-center-chat-size:${chatPercent}%">
                <div class="hb-command-center-agenda" data-tour-target="command-center-agenda">
                    ${loading ? dashboardLoadingMarkup('Loading today...') : commandCenterAgendaMarkup(items)}
                </div>
                <div class="hb-command-center-divider" role="separator" aria-orientation="horizontal" aria-valuemin="20" aria-valuemax="64" aria-valuenow="${Math.round(chatPercent)}" aria-label="Resize Bean chat area" tabindex="0" data-command-center-resizer="chat">
                    <span aria-hidden="true"></span>
                    <button class="hb-command-center-toggle" type="button" data-toggle-command-center-chat aria-label="${state.commandCenterChatCollapsed ? 'Expand chat' : 'Collapse chat'}" title="${state.commandCenterChatCollapsed ? 'Expand chat' : 'Collapse chat'}">${state.commandCenterChatCollapsed ? '^' : 'v'}</button>
                </div>
                <div class="hb-command-center-chat ${state.commandCenterChatCollapsed ? 'hb-command-center-chat-collapsed' : ''}" data-tour-target="command-center-chat">${chatMarkup({ compact: true, messageListId: 'hb-command-center-chat-messages' })}</div>
            </section>`;
    }

    function commandCenterAgendaPercent() {
        return (commandCenterAgendaRatio() * 100).toFixed(1);
    }

    function commandCenterChatPercent() {
        return (commandCenterChatRatio() * 100).toFixed(1);
    }

    function commandCenterAgendaRatio() {
        return clampCommandCenterAgendaRatio(Number(state.commandCenterAgendaRatio || (1 / 3)));
    }

    function commandCenterChatRatio() {
        return clampCommandCenterChatRatio(Number(state.commandCenterChatRatio || (1 / 3)));
    }

    function commandCenterMinAgendaRatio() {
        return 0.18;
    }

    function commandCenterMinChatRatio() {
        return 0.20;
    }

    function clampCommandCenterAgendaRatio(value, chatRatio = Number(state.commandCenterChatRatio || (1 / 3))) {
        const fallback = 1 / 3;
        const max = Math.max(commandCenterMinAgendaRatio(), 1 - clampCommandCenterChatRatio(chatRatio, fallback, { skipAgendaConstraint: true }));
        if (!Number.isFinite(value)) return Math.min(fallback, max);
        return Math.min(max, Math.max(commandCenterMinAgendaRatio(), value));
    }

    function clampCommandCenterChatRatio(value, agendaRatio = Number(state.commandCenterAgendaRatio || (1 / 3)), options = {}) {
        const fallback = 1 / 3;
        const agenda = options.skipAgendaConstraint ? Number(agendaRatio || fallback) : clampCommandCenterAgendaRatio(agendaRatio, fallback);
        const max = Math.max(commandCenterMinChatRatio(), 1 - agenda);
        if (!Number.isFinite(value)) return Math.min(fallback, max);
        return Math.min(max, Math.max(commandCenterMinChatRatio(), value));
    }

    function setCommandCenterAgendaRatio(value) {
        const chatRatio = commandCenterChatRatio();
        const ratio = clampCommandCenterAgendaRatio(value, chatRatio);
        state.commandCenterAgendaRatio = ratio;
        updateCommandCenterLayoutRatios(ratio, chatRatio);
    }

    function setCommandCenterChatRatio(value) {
        const agendaRatio = commandCenterAgendaRatio();
        const ratio = clampCommandCenterChatRatio(value, agendaRatio);
        state.commandCenterChatRatio = ratio;
        updateCommandCenterLayoutRatios(agendaRatio, ratio);
    }

    function updateCommandCenterLayoutRatios(agendaRatio = commandCenterAgendaRatio(), chatRatio = commandCenterChatRatio()) {
        const shell = mount.querySelector('[data-command-center-shell]');
        const chatDivider = mount.querySelector('[data-command-center-resizer="chat"]');
        shell?.style.setProperty('--hb-command-center-chat-size', `${(chatRatio * 100).toFixed(1)}%`);
        chatDivider?.setAttribute('aria-valuenow', String(Math.round(chatRatio * 100)));
        scheduleOnboardingTourLayout();
    }

    function bindCommandCenterResize() {
        mount.querySelectorAll('[data-command-center-resizer]').forEach((divider) => {
            divider.addEventListener('pointerdown', startCommandCenterResize);
            divider.addEventListener('keydown', handleCommandCenterResizeKey);
        });
    }

    function startCommandCenterResize(event) {
        if (event.target.closest('[data-toggle-command-center-chat]')) return;
        const shell = event.currentTarget.closest('[data-command-center-shell]');
        if (!shell) return;
        const target = event.currentTarget.dataset.commandCenterResizer || 'chat';
        if (target === 'chat' && state.commandCenterChatCollapsed) return;
        const rect = shell.getBoundingClientRect();
        if (rect.height <= 0) return;
        event.preventDefault();
        commandCenterResizeDrag = {
            pointerId: event.pointerId,
            shell,
            target,
            startY: event.clientY,
            height: rect.height,
            agendaRatio: commandCenterAgendaRatio(),
            chatRatio: commandCenterChatRatio(),
        };
        shell.classList.add('hb-command-center-card-resizing');
        event.currentTarget.setPointerCapture?.(event.pointerId);
        window.addEventListener('pointermove', updateCommandCenterResize, true);
        window.addEventListener('pointerup', finishCommandCenterResize, true);
        window.addEventListener('pointercancel', finishCommandCenterResize, true);
    }

    function updateCommandCenterResize(event) {
        const drag = commandCenterResizeDrag;
        if (!drag || event.pointerId !== drag.pointerId) return;
        event.preventDefault();
        const delta = (event.clientY - drag.startY) / drag.height;
        setCommandCenterChatRatio(drag.chatRatio - delta);
    }

    function finishCommandCenterResize(event) {
        const drag = commandCenterResizeDrag;
        if (!drag || event.pointerId !== drag.pointerId) return;
        drag.shell.classList.remove('hb-command-center-card-resizing');
        commandCenterResizeDrag = null;
        window.removeEventListener('pointermove', updateCommandCenterResize, true);
        window.removeEventListener('pointerup', finishCommandCenterResize, true);
        window.removeEventListener('pointercancel', finishCommandCenterResize, true);
        scrollChatToBottom();
    }

    function handleCommandCenterResizeKey(event) {
        const target = event.currentTarget.dataset.commandCenterResizer || 'chat';
        if (target === 'chat' && state.commandCenterChatCollapsed) return;
        const step = event.shiftKey ? 0.08 : 0.04;
        if (event.key === 'ArrowUp') {
            event.preventDefault();
            setCommandCenterChatRatio(commandCenterChatRatio() + step);
            scrollChatToBottom();
        } else if (event.key === 'ArrowDown') {
            event.preventDefault();
            setCommandCenterChatRatio(commandCenterChatRatio() - step);
        } else if (event.key === 'Home') {
            event.preventDefault();
            setCommandCenterChatRatio(commandCenterMinChatRatio());
        } else if (event.key === 'End') {
            event.preventDefault();
            setCommandCenterChatRatio(1 - commandCenterAgendaRatio());
            scrollChatToBottom();
        }
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
                    <strong>${escapeHtml(item.title || 'Untitled')}</strong>
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
                subtitle: [overdue ? 'overdue' : '', task.category || ''].filter(Boolean).join(' · '),
                isOverdue: overdue,
                color: itemColor(task),
            });
        });

        pendingReminders().forEach((reminder) => {
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
                subtitle: [overdue ? 'overdue' : '', reminder.category || ''].filter(Boolean).join(' · '),
                isOverdue: overdue,
                color: itemColor(reminder),
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
        return `<span class="${className}"><span class="hb-event-title-inner">${criticalStarMarkup(event)}${escapeHtml(eventTitleText(event))}</span>${eventPillIndicatorsMarkup(event, options)}</span>`;
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
            <p class="hb-item-meta">${googleConnected || outlookConnected ? 'Sync pulls selected external calendar events into Bean. Local Bean events stay local.' : 'Connect Google Calendar or Microsoft Outlook to import events into HeyBean.'}</p>
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
                    ${nav.slice(0, 2).map(navButton).join('')}
                    <span class="hb-bottom-bar-center-spacer" aria-hidden="true"></span>
                    ${nav.slice(2).map(navButton).join('')}
                </div>
                ${mobileBeanButtonMarkup()}
            </nav>`;
    }

    function mobileBeanButtonMarkup() {
        const active = state.selected === 'bean';
        const working = state.busy || beanWorkStatusActive();
        return `
            ${beanWorkStatusMarkup({ mobile: true })}
            <button class="hb-bean-button hb-mobile-bean-button ${active ? 'hb-bean-button-active' : ''} ${working ? 'hb-bean-button-working' : ''}" type="button" data-mobile-bean-button aria-label="Bean chat" title="Bean">
                <img src="${escapeAttr(logoUrl)}" alt="">
            </button>`;
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
                    <span class="hb-month-event-title">${escapeHtml(eventTitleText(event))}</span>
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
            <article class="hb-item hb-item-${kind} ${completed ? 'hb-item-complete' : ''} ${overdue ? 'hb-item-overdue' : ''}" style="--hb-item-color:${escapeAttr(color)}">
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
        return item?.is_critical || item?.isCritical ? '<span class="hb-star hb-critical-star" aria-hidden="true">★</span> ' : '';
    }

    function messageMarkup(message, index = 0, messages = []) {
        const user = message.role === 'user';
        const content = user ? (message.content || '') : safeAssistantDisplayContent(conversationalMessageContent(message.content || ''));
        const canEdit = user && !chatHasActiveTurn() && !String(message.id || '').startsWith('local-');
        const queued = user && message.metadata?.client_queue_status === 'queued';
        return `
            <article class="hb-message ${user ? 'hb-message-user' : ''}" ${user ? `data-message-id="${escapeAttr(message.id || '')}"` : ''}>
                <div class="hb-message-line">
                    ${message.progress ? '<span class="hb-spinner" style="width:13px;height:13px;border-width:2px"></span>' : ''}
                    <span class="hb-message-speaker ${user ? 'hb-message-speaker-user' : 'hb-message-speaker-bean'}">${user ? 'You' : 'Bean'}</span><span class="hb-message-separator"> - </span><span class="hb-message-body">${escapeHtml(content)}</span>
                    ${queued ? '<span class="hb-message-queue-status">Queued</span>' : ''}
                    ${user ? `<span class="hb-message-actions-inline">
                        <button class="hb-message-icon-action" type="button" data-copy-message="${escapeAttr(message.id || '')}" aria-label="Copy message" title="Copy">${icons.copy || icons.notes}</button>
                        ${canEdit ? `<button class="hb-message-icon-action" type="button" data-edit-message="${escapeAttr(message.id || '')}" aria-label="Edit message" title="Edit">${icons.edit}</button>` : ''}
                    </span>` : ''}
                </div>
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

    function safeAssistantDisplayContent(content) {
        const current = String(content || '').trim();
        if (!current) return content || '';
        const normalized = current.toLowerCase().replace(/\s+/g, ' ');
        const staleFailurePhrases = [
            'bean could not finish',
            'could not finish that request',
            'bean could not complete',
            'could not complete the requested change',
            'i could not complete',
            'i tried to check that live information',
            'lookup did not return',
            'lookup didn’t return',
            "lookup didn't return",
            'did not return a usable result',
            'no usable result',
            'could not get that live lookup back quickly enough',
            'couldn’t get that live lookup back quickly enough',
            "couldn't get that live lookup back quickly enough",
            'live lookup back quickly enough',
            'i’m still checking',
            "i'm still checking",
            'still checking live sources',
            'still checking live weather',
            'response did not come through',
            'something unexpected happened',
        ];
        return staleFailurePhrases.some((phrase) => normalized.includes(phrase))
            ? 'I’m checking the latest app state now. If I need one more detail, I’ll ask.'
            : content;
    }

    function assistantMessageShouldStayOutOfChat(message) {
        if (!message || message.role !== 'assistant') return false;
        const runtime = String(message.metadata?.runtime || '').trim();
        if (['missing_run_bridge', 'direct_queue_bridge', 'async_queue_bridge', 'failed_run_bridge'].includes(runtime)) return true;
        const normalized = String(message.content || '').toLowerCase().replace(/\s+/g, ' ').trim();
        return normalized === 'i’m checking the latest app state now. if i need one more detail, i’ll ask.'
            || normalized === "i'm checking the latest app state now. if i need one more detail, i'll ask."
            || normalized === 'i didn’t receive that request cleanly. please send it once more and i’ll take it from there.'
            || normalized === "i didn't receive that request cleanly. please send it once more and i'll take it from there."
            || normalized === 'i’m on it. i’m syncing against the latest app state now, and i’ll ask for one detail if i need it.'
            || normalized === "i'm on it. i'm syncing against the latest app state now, and i'll ask for one detail if i need it.";
    }

    function pushVisibleAssistantMessage(message, content = null) {
        if (!message || assistantMessageShouldStayOutOfChat(message)) return false;
        const visibleContent = content ?? safeAssistantDisplayContent(conversationalMessageContent(message.content || ''));
        if (!String(visibleContent || '').trim()) return false;
        const assistantId = String(message.id || '');
        if (assistantId && state.messages.some((item) => String(item.id || '') === assistantId)) return false;
        state.messages.push({
            ...message,
            content: visibleContent,
        });
        return true;
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
        if (modal.type === 'admin-usage-log') return adminUsageLogModalMarkup(modal.log);
        if (modal.type === 'admin-command-run') return adminCommandRunModalMarkup(modal);
        if (modal.type === 'external-calendar-connect') return externalCalendarConnectModalMarkup();
        if (modal.type === 'external-calendar-import') return externalCalendarImportModalMarkup(modal);
        if (modal.type === 'profile') return profileModalMarkup();
        if (modal.type === 'agent') return agentModalMarkup();
        if (modal.type === 'workspace') return workspaceModalMarkup(modal.mode, modal.workspace);
        if (modal.type === 'categories') return categoriesModalMarkup();
        if (modal.type === 'recurring-delete') return recurringDeleteModalMarkup(modal.item);
        return itemModalMarkup(modal.type, modal.item, modal.parentTask);
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
                    <h3>Import External Calendar</h3>
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
                        <button class="hb-button" type="submit" data-modal-save-button>${editing ? 'Save' : 'Create'}</button>
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
                    ${['confirmed', 'tentative', 'cancelled'].map((status) => `<option value="${status}" ${String(item?.status || 'confirmed') === status ? 'selected' : ''}>${capitalize(status)}</option>`).join('')}
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
        const startDate = allDay ? storedDateOnly(startSource) : dateOnly(startSource);
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
                ${labelInput('Date', 'allDayStart', 'date', startDate, allDay ? 'required' : 'disabled')}
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
        const selectedWorkspaceIds = workspaceAssignmentIds(sourceWorkspaceId, linked);
        const sourceWorkspace = allWorkspaces.find((workspace) => String(workspace.id) === String(selectedWorkspaceIds[0] || sourceWorkspaceId));
        return `
            <section class="hb-form-section hb-event-connections hb-workspace-picker" data-workspace-picker>
                <div class="hb-form-section-body">
                <input type="hidden" name="workspaceId" value="${escapeAttr(sourceWorkspaceId)}">
                <div class="hb-option-list hb-workspace-assignment-list" aria-label="Workspaces">
                    ${workspaceAssignmentRowsMarkup(allWorkspaces, selectedWorkspaceIds, sourceWorkspaceId, editing)}
                </div>
                ${kind === 'reminder' ? `<div data-reminder-recipient-options>${reminderRecipientOptionsMarkup(selectedWorkspaceIds, item)}</div>` : ''}
                </div>
            </section>`;
    }

    function workspaceAssignmentIds(sourceWorkspaceId, linked = new Set()) {
        return Array.from(new Set([
            sourceWorkspaceId || currentWorkspaceId(),
            ...Array.from(linked || []),
        ].map(String).filter(Boolean)));
    }

    function workspaceAssignmentRowsMarkup(allWorkspaces, selectedWorkspaceIds = [], sourceWorkspaceId = '', editing = false) {
        const selected = new Set(selectedWorkspaceIds.map(String));
        return allWorkspaces.map((workspace) => {
            const workspaceId = String(workspace.id || '');
            const checked = selected.has(workspaceId);
            const locked = editing && workspaceId === String(sourceWorkspaceId || '');
            return `<label class="hb-switch-row"><input type="checkbox" name="workspaceAssignmentIds" value="${escapeAttr(workspace.id)}" ${checked ? 'checked' : ''} ${locked ? 'disabled' : ''}> <span><strong>${escapeHtml(workspace.name || 'Workspace')}</strong></span></label>`;
        }).join('') || '<p class="hb-item-meta">No workspaces available.</p>';
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
        return `
            <div class="hb-modal-backdrop" role="dialog" aria-modal="true">
                <form class="hb-card hb-modal hb-form" data-modal-form="profile">
                    ${sectionTitle(icons.user, 'Account settings', '')}
                    ${labelInput('Email', 'email', 'email', state.user?.email || '', 'required')}
                    <label class="hb-label">Preferred maps app<select class="hb-select" name="preferredMapApp">
                        <option value="google" ${preferredMapApp === 'google' ? 'selected' : ''}>Google Maps</option>
                        <option value="apple" ${preferredMapApp === 'apple' ? 'selected' : ''}>Apple Maps</option>
                    </select></label>
                    <div class="hb-modal-actions"><button class="hb-button-secondary" type="button" data-close-modal>Cancel</button><button class="hb-button" type="submit">Save</button></div>
                </form>
            </div>`;
    }

    function agentModalMarkup() {
        const profile = currentAgentProfile();
        const priorities = new Set(profilePriorities(profile));
        const personality = profilePersonality(profile);
        const options = ['balanced', 'coach', 'organizer', 'creative', 'direct', 'gentle'];
        return `
            <div class="hb-modal-backdrop" role="dialog" aria-modal="true">
                <form class="hb-card hb-modal hb-form" data-modal-form="agent">
                    ${sectionTitle(icons.tune, 'Edit Bean preferences', 'Review the current settings and save only what you want to change.')}
                    <input type="hidden" name="workspaceId" value="${escapeAttr(currentWorkspaceId() || '')}">
                    <label class="hb-label">Choose Bean’s personality<select class="hb-select" name="personality">${options.map((option) => `<option value="${option}" ${option === personality ? 'selected' : ''}>${personalityLabel(option)}</option>`).join('')}</select></label>
                    <div class="hb-label">What should Bean prioritize?
                        <div class="hb-tabs">${['Work', 'Family', 'Health', 'Planning', 'Reminders', 'Focus'].map((priority) => `<label class="hb-chip"><input type="checkbox" name="priorities" value="${priority}" ${priorities.has(priority) ? 'checked' : ''}> ${priority}</label>`).join('')}</div>
                    </div>
                    <label class="hb-label">Anything Bean should remember?<textarea class="hb-textarea" name="context" placeholder="Example: I work nights, protect family time, and need gentle nudges.">${escapeHtml(profileOnboardingContext(profile))}</textarea></label>
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
        if (eventIsGeneratedOccurrence(item)) return 'none';
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
        mount.querySelector('[data-action="guided-onboarding"]')?.addEventListener('submit', submitGuidedOnboarding);
        mount.querySelectorAll('[data-guided-theme-mode]').forEach((button) => button.addEventListener('click', () => {
            if (guidedSignupInputLocked()) return;
            selectGuidedThemeMode(button.dataset.guidedThemeMode || '');
        }));
        mount.querySelectorAll('[data-guided-personality]').forEach((button) => button.addEventListener('click', () => {
            if (guidedSignupInputLocked()) return;
            selectGuidedPersonality(button.dataset.guidedPersonality || '');
        }));
        mount.querySelectorAll('[data-guided-location]').forEach((button) => button.addEventListener('click', () => {
            if (guidedSignupInputLocked()) return;
            if (button.dataset.guidedLocation === 'allow') {
                requestGuidedLocationFromClick();
            } else {
                skipGuidedLocation();
            }
        }));
        mount.querySelectorAll('[data-guided-tour-choice]').forEach((button) => button.addEventListener('click', () => {
            if (guidedSignupInputLocked()) return;
            if (button.dataset.guidedTourChoice === 'tour') {
                launchGuidedOnboardingTour();
            } else {
                void goToGuidedPlan(true);
            }
        }));
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
        mount.querySelector('[data-subscribe-coupon-code]')?.addEventListener('keydown', (event) => {
            if (event.key === 'Enter') {
                event.preventDefault();
                redeemCouponCodeFromInput('subscribe');
            }
        });
        mount.querySelector('[data-subscribe-apply-coupon]')?.addEventListener('click', () => redeemCouponCodeFromInput('subscribe'));
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
        const personality = guidedSignupPersonalities.some((option) => option.key === data.agent_personality)
            ? data.agent_personality
            : 'balanced';
        const homeCity = String(data.home_city || '').trim();
        const context = [
            'Completed guided Bean signup onboarding.',
            `Preferred Bean personality: ${signupPersonalityBaseLabel(personality)}.`,
            homeCity ? `City-level location: ${homeCity}.` : '',
        ].filter(Boolean).join(' ');

        return {
            name: data.name,
            email: data.email,
            password: data.password,
            password_confirmation: data.password_confirmation,
            ...(data.plan ? { plan: data.plan } : {}),
            billing_interval: normalizedBillingInterval(data.billing_interval || state.selectedBillingInterval),
            theme_mode: ['light', 'dark', 'auto'].includes(data.theme_mode) ? data.theme_mode : 'light',
            agent_personality: personality,
            onboarding_priorities: ['Planning', 'Reminders', 'Focus'],
            onboarding_context: context,
            ...(homeCity ? { home_city: homeCity } : {}),
        };
    }

    function signupPersonalityBaseLabel(personality) {
        return {
            balanced: 'Balanced',
            coach: 'Coach',
            organizer: 'Organizer',
            creative: 'Creative',
            direct: 'Direct',
            gentle: 'Gentle',
        }[personality] || 'Balanced';
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
        mount.querySelectorAll('[data-nav]').forEach((button) => button.addEventListener('click', () => {
            state.selected = button.dataset.nav;
            state.error = '';
            state.notice = '';
            history.pushState({}, '', pathForView(state.selected));
            render();
            if (state.selected === 'admin') loadAdminUsage();
            scrollChatToBottom();
        }));
        mount.querySelectorAll('[data-mobile-bean-button]').forEach((button) => {
            button.addEventListener('click', openMobileBeanChat);
        });
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
            closeOnboardingTour({ openCalendarImport: true });
            render();
        });
        mount.querySelector('[data-admin-login]')?.addEventListener('click', () => {
            stopDashboardChangeFeed();
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
        mount.querySelector('[data-toggle-command-center-chat]')?.addEventListener('click', () => {
            state.commandCenterChatCollapsed = !state.commandCenterChatCollapsed;
            render();
            if (!state.commandCenterChatCollapsed) scrollChatToBottom();
        });
        bindCommandCenterResize();
        mount.querySelector('[data-refresh-admin]')?.addEventListener('click', () => loadAdminUsage(true));
        mount.querySelector('[data-admin-settings-form]')?.addEventListener('submit', saveAdminSettings);
        mount.querySelector('[data-admin-plan-limits-form]')?.addEventListener('submit', saveAdminPlanLimits);
        mount.querySelector('[data-admin-coupon-form]')?.addEventListener('submit', createAdminCouponCode);
        mount.querySelectorAll('[data-admin-coupon-delete]').forEach((button) => button.addEventListener('click', () => deleteAdminCouponCode(button.dataset.adminCouponDelete)));
        mount.querySelectorAll('[data-enterprise-limit-form]').forEach((form) => form.addEventListener('submit', saveEnterpriseLimits));
        mount.querySelectorAll('[data-enterprise-limit-delete]').forEach((button) => button.addEventListener('click', () => deleteEnterpriseLimits(button.dataset.enterpriseLimitDelete)));
        mount.querySelector('[data-update-hermes]')?.addEventListener('click', updateHermesRuntime);
        mount.querySelectorAll('[data-user-growth-range]').forEach((button) => button.addEventListener('click', () => setAdminUserGrowthRange(button.dataset.userGrowthRange)));
        mount.querySelector('[data-toggle-archived-issues]')?.addEventListener('click', () => { state.adminArchivedIssuesOpen = !state.adminArchivedIssuesOpen; render(); });
        mount.querySelectorAll('[data-issue-status]').forEach((button) => button.addEventListener('click', () => updateIssueReportStatus(button.dataset.issueStatus, button.dataset.status)));
        mount.querySelectorAll('[data-admin-log-id]').forEach((button) => button.addEventListener('click', () => openAdminUsageLog(button.dataset.adminLogId)));
        mount.querySelectorAll('[data-open-create]').forEach((button) => button.addEventListener('click', () => openModal(button.dataset.openCreate)));
        mount.querySelectorAll('[data-create-note]').forEach((button) => button.addEventListener('click', createNote));
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
        mount.querySelectorAll('[data-note-command]').forEach((button) => {
            button.addEventListener('pointerdown', (event) => event.preventDefault());
            button.addEventListener('click', () => execNoteCommand(button.dataset.noteCommand, button.dataset.noteCommandValue));
        });
        bindNoteEditorAutosave();
        mount.querySelector('[data-memory-search]')?.addEventListener('input', (event) => {
            state.memorySearch = event.currentTarget.value;
            render();
        });
        mount.querySelector('[data-memory-type-filter]')?.addEventListener('change', (event) => {
            state.memoryTypeFilter = event.currentTarget.value;
            render();
        });
        mount.querySelector('[data-refresh-memory]')?.addEventListener('click', refreshMemory);
        mount.querySelector('[data-memory-create-form]')?.addEventListener('submit', createMemory);
        mount.querySelectorAll('[data-memory-update-form]').forEach((form) => form.addEventListener('submit', updateMemory));
        mount.querySelectorAll('[data-memory-forget]').forEach((button) => button.addEventListener('click', () => forgetMemory(button.dataset.memoryForget)));
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
        mount.querySelectorAll('[data-theme-mode-option]').forEach((input) => input.addEventListener('change', updateThemeModePreference));
        mount.querySelector('[data-home-city-form]')?.addEventListener('submit', updateHomeCityPreference);
        mount.querySelector('[data-clear-home-city]')?.addEventListener('click', clearHomeCityPreference);
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
        mount.querySelectorAll('[data-voice-toggle]').forEach((button) => {
            button.addEventListener('click', toggleVoiceWakeListening);
        });
        mount.querySelectorAll('[data-cancel-turn]').forEach((button) => button.addEventListener('click', cancelBeanTurn));
        mount.querySelectorAll('[data-copy-message]').forEach((button) => button.addEventListener('click', () => copyChatMessage(button.dataset.copyMessage)));
        mount.querySelectorAll('[data-edit-message]').forEach((button) => button.addEventListener('click', () => editChatMessage(button.dataset.editMessage)));
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

    async function createNote() {
        state.error = '';
        try {
            const body = {
                title: 'New Note',
                body_html: '',
                plain_text: '',
                note_folder_id: /^\d+$/.test(String(state.selectedNoteFolderId || '')) ? Number(state.selectedNoteFolderId) : null,
            };
            const note = await api(workspaceScopedPath('/notes'), { method: 'POST', body });
            state.notes = normalizeNotes(upsertById(state.notes, note));
            state.selectedNoteId = String(note.id);
            state.notesDetailOpen = true;
            state.selected = 'notes';
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

    function openMobileBeanChat() {
        state.selected = 'bean';
        state.error = '';
        state.notice = '';
        history.pushState({}, '', pathForView(state.selected));
        render();
        scrollChatToBottom();
        mount.querySelector('.hb-chat textarea')?.focus();
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
        try {
            const folder = await api(workspaceScopedPath('/note-folders'), { method: 'POST', body: { name: name.trim() } });
            state.noteFolders = normalizeNoteFolders([...state.noteFolders, folder]);
            state.selectedNoteFolderId = String(folder.id);
            saveDashboardCache();
            render();
        } catch (error) {
            state.error = friendlyError(error, 'create that folder');
            render();
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
        if (noteIsLocked(note)) return;
        form.querySelector('[name="title"]')?.addEventListener('input', () => scheduleNoteAutosave(form));
        form.querySelector('[name="note_folder_id"]')?.addEventListener('change', () => scheduleNoteAutosave(form, true));
        const body = form.querySelector('[data-note-body]');
        body?.addEventListener('input', () => scheduleNoteAutosave(form));
        body?.addEventListener('blur', () => flushNoteAutosave(form));
        body?.addEventListener('keydown', (event) => handleNoteBodyKeydown(event, form));
        body?.addEventListener('click', (event) => handleNoteBodyClick(event, form));
    }

    function notePayloadFromForm(form) {
        const bodyNode = form.querySelector('[data-note-body]');
        const bodyHtml = bodyNode?.innerHTML || '';
        const plainText = noteBodyPlainText(bodyNode);
        return {
            title: String(form.elements.title?.value || '').trim() || 'New Note',
            body_html: bodyHtml,
            plain_text: plainText,
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
            noteFolderId: body.note_folder_id,
        }));
        setNoteSaveStatus('Saving...');
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

    function setNoteSaveStatus(text) {
        const status = mount.querySelector('.hb-note-save-state');
        if (status) status.textContent = text;
    }

    async function saveNotePayload(id, body) {
        if (!id) return;
        noteAutosaveTimers.delete(String(id));
        state.notesSaving = true;
        state.error = '';
        setNoteSaveStatus('Saving...');
        try {
            const note = await api(`/notes/${encodeURIComponent(id)}`, { method: 'PATCH', body });
            state.notes = normalizeNotes(upsertById(state.notes, note));
            state.selectedNoteId = String(note.id);
            saveDashboardCache();
            setNoteSaveStatus('Auto-saved');
        } catch (error) {
            state.error = friendlyError(error, 'save that note');
            setNoteSaveStatus('Save failed');
            render();
        } finally {
            state.notesSaving = false;
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

    function execNoteCommand(command, value = '') {
        const form = mount.querySelector('[data-note-editor]');
        const note = findById(state.notes, form?.dataset?.noteEditor);
        if (noteIsLocked(note)) return;
        const body = mount.querySelector('[data-note-body]');
        if (command === 'checkbox') {
            toggleCurrentNoteCheckboxLine(body);
            if (form) scheduleNoteAutosave(form, true);
            return;
        }
        if (command === 'bullet') {
            updateCurrentNoteLine(body, '• ');
            if (form) scheduleNoteAutosave(form, true);
            return;
        }
        if (command === 'indent' || command === 'outdent') {
            indentCurrentNoteLine(body, command === 'indent' ? 1 : -1);
            if (form) scheduleNoteAutosave(form, true);
            return;
        }
        body?.focus();
        document.execCommand(command, false, value || null);
        if (form) scheduleNoteAutosave(form, true);
    }

    function handleNoteBodyKeydown(event, form) {
        if (event.key !== 'Enter' || event.shiftKey || event.metaKey || event.ctrlKey || event.altKey) return;
        const body = event.currentTarget;
        const text = noteBodyPlainText(body);
        const offset = editableTextOffset(body);
        const line = noteTextLineAt(text, offset);
        const marker = noteLineMarker(line.text);
        const indentation = (line.text.match(/^\s*/) || [''])[0];
        const prefix = marker ? `${marker.indent}${marker.marker}` : indentation;
        if (!prefix) return;
        event.preventDefault();
        const nextText = `${text.slice(0, offset)}\n${prefix}${text.slice(offset)}`;
        replaceNoteBodyText(body, nextText, offset + 1 + prefix.length);
        scheduleNoteAutosave(form);
    }

    function handleNoteBodyClick(event, form) {
        const marker = event.target.closest?.('.hb-note-checkbox-marker');
        if (!marker) return;
        event.preventDefault();
        const body = form.querySelector('[data-note-body]');
        const text = noteBodyPlainText(body);
        const row = marker.closest('div');
        const rows = Array.from(body.children);
        const rowIndex = Math.max(0, rows.indexOf(row));
        let lineStart = 0;
        for (let index = 0; index < rowIndex; index += 1) {
            lineStart += noteNodePlainText(rows[index]).length + 1;
        }
        const line = noteTextLineAt(text, lineStart);
        const current = noteLineMarker(line.text);
        if (!current || !current.marker.startsWith('☐') && !current.marker.startsWith('☑')) return;
        const checked = current.marker.startsWith('☑');
        const replacement = checked ? '☐ ' : '☑ ';
        const markerStart = line.start + current.start;
        const markerEnd = line.start + current.end;
        const nextText = `${text.slice(0, markerStart)}${replacement}${text.slice(markerEnd)}`;
        replaceNoteBodyText(body, nextText, markerStart + replacement.length);
        scheduleNoteAutosave(form, true);
    }

    function updateCurrentNoteLine(body, prefix) {
        if (!body) return;
        const text = noteBodyPlainText(body);
        const offset = editableTextOffset(body);
        const line = noteTextLineAt(text, offset);
        const marker = noteLineMarker(line.text);
        const indentation = (line.text.match(/^\s*/) || [''])[0];
        const markerStart = line.start + (marker ? marker.start : indentation.length);
        const markerEnd = line.start + (marker ? marker.end : indentation.length);
        const nextText = `${text.slice(0, markerStart)}${prefix}${text.slice(markerEnd)}`;
        replaceNoteBodyText(body, nextText, markerStart + prefix.length);
    }

    function toggleCurrentNoteCheckboxLine(body) {
        if (!body) return;
        const text = noteBodyPlainText(body);
        const offset = editableTextOffset(body);
        const line = noteTextLineAt(text, offset);
        const marker = noteLineMarker(line.text);
        if (marker && (marker.marker.startsWith('☐') || marker.marker.startsWith('☑'))) {
            const markerStart = line.start + marker.start;
            const markerEnd = line.start + marker.end;
            const nextText = `${text.slice(0, markerStart)}${text.slice(markerEnd)}`;
            replaceNoteBodyText(body, nextText, markerStart);
            return;
        }
        updateCurrentNoteLine(body, '☐ ');
    }

    function indentCurrentNoteLine(body, amount) {
        if (!body) return;
        const text = noteBodyPlainText(body);
        const offset = editableTextOffset(body);
        const line = noteTextLineAt(text, offset);
        const indentMatch = line.text.match(/^\s*/) || [''];
        const currentIndent = indentMatch[0] || '';
        const nextIndent = amount > 0
            ? `${currentIndent}  `
            : currentIndent.slice(0, Math.max(0, currentIndent.length - 2));
        const nextText = `${text.slice(0, line.start)}${nextIndent}${line.text.slice(currentIndent.length)}${text.slice(line.end)}`;
        const delta = nextIndent.length - currentIndent.length;
        replaceNoteBodyText(body, nextText, Math.max(line.start + nextIndent.length, offset + delta));
    }

    async function refreshMemory() {
        try {
            const [items, summaries, history] = await Promise.all([
                api(workspaceScopedPath('/memory-items')),
                api(workspaceScopedPath('/memory-summaries')),
                api(workspaceScopedPath('/memory/request-history?limit=10')),
            ]);
            state.memoryItems = normalizeList(items);
            state.memorySummaries = normalizeList(summaries);
            state.memoryHistory = normalizeList(history);
            saveDashboardCache();
            render();
        } catch (error) {
            state.error = friendlyError(error, "refresh Bean's Knowledge");
            render();
        }
    }

    async function createMemory(event) {
        event.preventDefault();
        const form = event.currentTarget;
        const content = String(form.elements.content?.value || '').trim();
        if (!content) return;
        state.memorySaving = true;
        state.error = '';
        render();
        try {
            const item = await api(workspaceScopedPath('/memory-items'), {
                method: 'POST',
                body: {
                    type: form.elements.type?.value || 'fact',
                    content,
                    confidence: 95,
                    importance: 75,
                    metadata: { source: 'memory_screen' },
                },
            });
            state.memoryItems = upsertById(state.memoryItems, item);
            saveDashboardCache();
        } catch (error) {
            state.error = friendlyError(error, 'save that knowledge');
        } finally {
            state.memorySaving = false;
            render();
        }
    }

    async function updateMemory(event) {
        event.preventDefault();
        const form = event.currentTarget;
        const id = form.dataset.memoryUpdateForm;
        if (!id) return;
        try {
            const item = await api(`/memory-items/${encodeURIComponent(id)}`, {
                method: 'PATCH',
                body: {
                    type: form.elements.type?.value || 'fact',
                    title: String(form.elements.title?.value || '').trim() || null,
                    content: String(form.elements.content?.value || '').trim(),
                },
            });
            state.memoryItems = upsertById(state.memoryItems, item);
            state.notice = 'Knowledge saved.';
            saveDashboardCache();
            render();
        } catch (error) {
            state.error = friendlyError(error, 'update that knowledge');
            render();
        }
    }

    async function forgetMemory(id) {
        if (!id || !window.confirm('Forget this knowledge?')) return;
        const previous = state.memoryItems;
        state.memoryItems = state.memoryItems.filter((item) => String(item.id) !== String(id));
        render();
        try {
            await api(`/memory-items/${encodeURIComponent(id)}`, { method: 'DELETE' });
            saveDashboardCache();
        } catch (error) {
            state.memoryItems = previous;
            state.error = friendlyError(error, 'forget that knowledge');
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
                if (state.modal?.type === 'admin-command-run' && adminCommandRunActive(state.modal?.status)) return;
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
                    },
                });
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
                            external_lookup_model: value('external_lookup_model'),
                        },
                        kill_switches: {
                            bean_chat_enabled: Boolean(form.querySelector('input[name="bean_chat_enabled"]')?.checked),
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
        state.adminUsageLoading = true;
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
            state.adminUsageLoading = false;
            render();
        }
    }

    async function deleteAdminCouponCode(id) {
        if (!id || state.adminUsageLoading) return;
        if (!confirm('Delete this coupon code? The code can no longer be redeemed.')) return;
        state.adminUsageLoading = true;
        state.error = '';
        render();
        try {
            await api(`/admin/coupon-codes/${encodeURIComponent(id)}`, { method: 'DELETE' });
            state.adminCoupons = await api('/admin/coupon-codes');
            state.notice = 'Coupon code deleted.';
        } catch (error) {
            state.error = friendlyError(error, 'delete the coupon code');
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
            note_limit: nullableNumber(container.querySelector('input[name="note_limit"]')?.value),
            recurring_tasks_enabled: checked('recurring_tasks_enabled'),
            recurring_reminders_enabled: checked('recurring_reminders_enabled'),
            recurring_calendar_enabled: checked('recurring_calendar_enabled'),
            email_reminders_enabled: checked('email_reminders_enabled'),
            notes_enabled: checked('notes_enabled'),
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
                    ...existingMetadata,
                    ...(parentTaskId ? { parent_task_id: Number(parentTaskId) } : {}),
                    ...recurrence.metadata,
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
                status: item?.status || 'pending',
                category: data.category || null,
                color,
                metadata: {
                    ...existingMetadata,
                    ...recurrence.metadata,
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
            const recurrence = generatedOccurrence ? { value: null, metadata: {} } : recurrenceFormData(form, data);
            const metadata = {
                ...existingMetadata,
                ...recurrence.metadata,
                ...eventPlaceMetadataFromFormData(data),
                all_day: allDay,
            };
            if (generatedOccurrence) {
                metadata.recurrence = 'none';
                delete metadata.specific_days;
                delete metadata.specificDays;
                delete metadata.days;
                delete metadata.interval;
                delete metadata.interval_unit;
                delete metadata.intervalUnit;
                delete metadata.unit;
            }
            const body = {
                title: data.title,
                description: data.description || null,
                location: data.location || null,
                starts_at: allDay ? fromDateInputStart(data.allDayStart) : fromDatetimeLocal(data.time),
                ends_at: allDay ? fromDateInputEndInclusive(data.allDayStart) : fromDatetimeLocal(data.endsAt),
                category: data.category || null,
                color,
                is_critical: form.elements.critical?.checked || false,
                recurrence: recurrence.value,
                status: data.status || 'confirmed',
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
        const recurrence = body.recurrence || event.recurrence || body.metadata?.recurrence || 'none';
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

    function selectedWorkspaceAssignmentIds(form) {
        return Array.from(form?.querySelectorAll('input[name="workspaceAssignmentIds"]:checked') || [])
            .map((input) => Number(input.value))
            .filter(Boolean);
    }

    function selectedPrimaryWorkspaceId(form, item = null) {
        const selected = selectedWorkspaceAssignmentIds(form).map(String);
        const savedWorkspaceId = String(item?.workspace_id || item?.workspaceId || '');
        if (savedWorkspaceId && selected.includes(savedWorkspaceId)) return savedWorkspaceId;
        const defaultWorkspaceId = String(currentWorkspaceId() || '');
        if (defaultWorkspaceId && selected.includes(defaultWorkspaceId)) return defaultWorkspaceId;
        return selected[0] || defaultWorkspaceId || savedWorkspaceId || '';
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
            const allDayStart = form.querySelector('input[name="allDayStart"]');
            if (startInput?.value && allDayStart) {
                setDateTimePickerValue(allDayStart, dateOnly(startInput.value), {
                    dispatch: false,
                });
            }
        } else {
            const startInput = form.querySelector('input[name="time"]');
            const endInput = form.querySelector('input[name="endsAt"]');
            const allDayStart = form.querySelector('input[name="allDayStart"]');
            if (allDayStart?.value && startInput && endInput && !startInput.value) {
                const start = parseLocalDate(allDayStart.value);
                start.setHours(9, 0, 0, 0);
                setDateTimePickerValue(startInput, toDatetimeLocal(start), {
                    dispatch: false,
                });
                setDateTimePickerValue(endInput, toDatetimeLocal(defaultEventEnd(start)), {
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
            if (field.name === 'time' || field.name === 'allDayStart') {
                field.required = enabled && field.name !== 'endsAt';
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
        state.chatDraft = event.currentTarget.value;
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

    function chatHasActiveTurn() {
        return state.busy || activeChatRequestId > 0;
    }

    function enqueueChatContent(content, options = {}) {
        const queueId = `queued-${Date.now()}-${++chatQueueCounter}`;
        const queued = {
            id: queueId,
            content,
            editingMessageId: options.editingMessageId || '',
        };
        state.chatQueue.push(queued);
        state.messages.push({
            id: queueId,
            role: 'user',
            content,
            metadata: { client_queue_status: 'queued' },
        });
        state.chatDraft = '';
        state.editingChatMessageId = '';
        render();
        scrollChatToBottom();
    }

    function scheduleChatQueueDrain() {
        window.setTimeout(() => {
            drainChatQueue().catch(() => {});
        }, 0);
    }

    async function drainChatQueue() {
        if (chatHasActiveTurn() || !state.chatQueue.length) return;
        const queued = state.chatQueue.shift();
        state.messages = state.messages.filter((message) => String(message.id || '') !== String(queued.id));
        await sendChatContent(queued.content, queued.editingMessageId ? { editingMessageId: queued.editingMessageId } : {});
    }

    function updateVoiceWakeDraft(text) {
        state.chatDraft = text;
        const textarea = mount.querySelector('textarea[name="message"]');
        if (textarea) {
            textarea.value = text;
            resizeChatInput(textarea);
        }
    }

    function normalizedVoiceCommand(text) {
        return String(text || '')
            .toLowerCase()
            .replace(/[^a-z0-9\s]+/g, ' ')
            .replace(/\s+/g, ' ')
            .trim();
    }

    function voiceWakeWordPattern() {
        return /\bhey[\s,.-]*(bean|ben|bin|bing|being|beane|beam)\b/i;
    }

    function stripVoiceWakeWords(text) {
        return String(text || '')
            .replace(/\bhey[\s,.-]*(bean|ben|bin|bing|being|beane|beam)\b/ig, '')
            .replace(/\s+/g, ' ')
            .trim();
    }

    function voiceStopCommand(text) {
        const normalized = normalizedVoiceCommand(stripVoiceWakeWords(text));
        if (!normalized) return false;
        return /^(thanks|thank you|thx|nevermind|never mind|cancel|stop|stop listening|stop talking|that'?s all|that is all|all done|we'?re good|were good|i'?m good|im good|no thanks|no thank you|goodbye|bye|shut up)( bean| ben| bin| bing| being)?$/i.test(normalized)
            || /^(bean|ben|bin|bing|being) (thanks|thank you|nevermind|never mind|cancel|stop|stop listening|stop talking|that'?s all|that is all|all done|goodbye|bye|shut up)$/i.test(normalized);
    }

    function textAfterWakeWord(text) {
        return String(text || '')
            .replace(/^.*?\bhey[\s,.-]*(bean|ben|bin|bing|being|beane|beam)\b[\s,.:;!?-]*/i, '')
            .trim();
    }

    function realtimeVoiceStatusAnswer(text) {
        const normalized = normalizedVoiceCommand(stripVoiceWakeWords(text));
        if (/^(can you hear me|do you hear me|are you listening|can you listen|is the mic working|can you hear my voice)$/.test(normalized)) {
            return 'Yes — I can hear you. What would you like me to help with?';
        }
        return '';
    }

    function realtimeDirectAnswerInstructions(command) {
        const timezone = Intl.DateTimeFormat().resolvedOptions().timeZone || 'the user\'s local timezone';
        const now = new Date();
        return [
            'You are Bean in a live voice conversation. Answer the user directly, warmly, and very briefly.',
            'This is a simple conversational question that does not need HeyBean app data, private workspace data, actions, or external lookups.',
            `User local time context: ${now.toLocaleString(undefined, { dateStyle: 'full', timeStyle: 'short' })} (${timezone}).`,
            'If the user asks for date or time, use that local time context. Do not say you cannot hear audio; this is an active voice session.',
            `User said: ${command}`,
        ].join('\n');
    }

    function realtimeWorkingAckInstructions(command) {
        return [
            'You are Bean in a live voice conversation. Give one short, natural acknowledgement that you heard the user and are working on it.',
            'Vary the wording like a real person. Do not use a canned phrase. Do not answer the request yet. Do not mention tools, Laravel, OpenAI, or systems.',
            'Keep it under 12 words.',
            `User request: ${command}`,
        ].join('\n');
    }

    function realtimeWorkingStatus(command) {
        const normalized = normalizedVoiceCommand(command);
        if (/\b(calendar|event|schedule|appointment)\b/.test(normalized)) return 'Checking your calendar…';
        if (/\b(task|todo|to do)\b/.test(normalized)) return 'Checking your tasks…';
        if (/\b(reminder|reminders)\b/.test(normalized)) return 'Checking your reminders…';
        if (/\b(note|notes)\b/.test(normalized)) return 'Checking your notes…';
        return 'Bean is working…';
    }

    function realtimeFollowUpActive() {
        return realtimeFollowUpUntil > Date.now();
    }

    function clearRealtimeFollowUpWindow() {
        window.clearTimeout(realtimeFollowUpTimer);
        realtimeFollowUpTimer = 0;
        realtimeFollowUpUntil = 0;
    }

    function clearQueuedRealtimeFollowUp() {
        window.clearTimeout(realtimeQueuedFollowUpTimer);
        realtimeQueuedFollowUpTimer = 0;
        realtimeQueuedFollowUpTranscript = '';
    }

    function realtimeTurnStillActive() {
        return realtimeLaravelTurnInFlight
            || realtimeResponseActive
            || chatHasActiveTurn()
            || Date.now() < realtimeIgnoreInputUntil
            || Date.now() < realtimeTurnGuardUntil;
    }

    function scheduleQueuedRealtimeFollowUp() {
        window.clearTimeout(realtimeQueuedFollowUpTimer);
        const remainingGuard = Math.max(realtimeIgnoreInputUntil, realtimeTurnGuardUntil) - Date.now() + 100;
        const waitMs = Math.max(250, Math.min(2500, remainingGuard));
        realtimeQueuedFollowUpTimer = window.setTimeout(() => {
            realtimeQueuedFollowUpTimer = 0;
            if (!realtimeQueuedFollowUpTranscript) return;
            if (!state.voiceWakeListening) {
                clearQueuedRealtimeFollowUp();
                return;
            }
            if (realtimeTurnStillActive()) {
                scheduleQueuedRealtimeFollowUp();
                return;
            }
            if (!realtimeFollowUpActive() && !realtimeWakeActivated) {
                clearQueuedRealtimeFollowUp();
                return;
            }
            const queued = realtimeQueuedFollowUpTranscript;
            realtimeQueuedFollowUpTranscript = '';
            handleRealtimeUserTranscript(queued).catch((error) => {
                state.error = friendlyError(error, 'send realtime follow-up');
                render();
            });
        }, waitMs);
    }

    function queueRealtimeFollowUpTranscript(text) {
        const content = String(text || '').trim();
        if (!canQueueRealtimeFollowUp({
            content,
            wakeActivated: realtimeWakeActivated,
            followUpActive: realtimeFollowUpActive(),
            turnActive: realtimeTurnStillActive(),
        })) return;
        realtimeQueuedFollowUpTranscript = content;
        scheduleQueuedRealtimeFollowUp();
    }

    function setRealtimeFollowUpWindow() {
        window.clearTimeout(realtimeFollowUpTimer);
        realtimeFollowUpTimer = 0;
        realtimeFollowUpUntil = realtimeFollowUpExpiry();
        realtimeWakeActivated = true;
    }

    function realtimeSend(event) {
        if (!realtimeDataChannel || realtimeDataChannel.readyState !== 'open') return false;
        realtimeDataChannel.send(JSON.stringify(event));
        return true;
    }

    function stopBeanVoicePlayback(options = {}) {
        if (realtimeResponseActive) {
            try {
                realtimeSend({ type: 'response.cancel' });
            } catch (_) {}
            realtimeResponseActive = false;
        }
        realtimeResponseLifecycle.cancel();
        if (options.teardown && realtimeRemoteAudio) {
            try {
                realtimeRemoteAudio.pause();
                realtimeRemoteAudio.currentTime = 0;
                realtimeRemoteAudio.srcObject = null;
            } catch (_) {}
        }
    }

    function pauseVoiceWakeRecognitionForBean() {
        // Realtime uses server-side VAD and interruption instead of pausing local recognition.
    }

    function setVoiceFollowUpWindow() {
        setRealtimeFollowUpWindow();
    }

    function clearVoiceFiller() {
        // REST voice filler removed; realtime audio provides immediate acknowledgement/turn state.
    }

    function scheduleVoiceFiller() {
        // REST voice filler removed; realtime audio provides immediate acknowledgement/turn state.
    }

    async function playBeanVoiceResponse(text) {
        return speakRealtimeBeanText(text);
    }

    async function speakRealtimeInstructions(instructions, options = {}) {
        const content = String(instructions || '').trim();
        if (!content || !realtimeDataChannel || realtimeDataChannel.readyState !== 'open') return null;
        realtimeSuppressNextAssistantTranscript = options.suppressTranscript !== false;
        realtimeAssistantDraft = '';
        stopBeanVoicePlayback();
        realtimeResponseActive = true;
        realtimeIgnoreInputUntil = Date.now() + 2500;
        const completion = realtimeResponseLifecycle.begin(options.purpose || 'speech');
        if (!realtimeSend(buildRealtimeResponseEvent(content))) {
            realtimeResponseActive = false;
            realtimeResponseLifecycle.cancel();
            return null;
        }
        return completion;
    }

    async function speakRealtimeBeanText(text) {
        const content = String(text || '').trim();
        if (!content || !realtimeDataChannel || realtimeDataChannel.readyState !== 'open') return null;
        return speakRealtimeInstructions(`Speak this HeyBean answer exactly and do not add any extra facts or tasks:\n\n${content}`, { purpose: 'final' });
    }

    function realtimeInstructionsUpdate() {
        return {
            type: 'session.update',
            session: {
                type: 'realtime',
                instructions: 'You are Bean inside an active HeyBean realtime voice session. Do not respond before the user says Hey Bean unless a follow-up window is active. During the follow-up window, accept natural follow-ups without the wake word. If the user says thanks, nevermind, cancel, stop, stop talking, stop listening, that is all, all done, no thanks, goodbye, bye, shut up, or close variants, stop speaking and end the session. Keep voice answers concise. Use send_bean_request for HeyBean app data, task/reminder/note/calendar/memory mutations, approvals, or longer-running agent work.',
            },
        };
    }

    async function openRealtimeSession() {
        if (!state.session?.id) {
            const onboarding = needsBeanOnboarding();
            state.session = await api('/assistant/sessions', {
                method: 'POST',
                body: chatSessionPayload(onboarding),
            });
        }
        return api('/assistant/voice/realtime/session', {
            method: 'POST',
            body: { timezone: Intl.DateTimeFormat().resolvedOptions().timeZone || '' },
            timeoutMs: 15000,
        });
    }

    async function connectRealtimeVoice() {
        if (!navigator.mediaDevices?.getUserMedia || typeof RTCPeerConnection === 'undefined') {
            throw new Error('Realtime voice needs microphone and WebRTC support in this browser.');
        }

        realtimeSession = await openRealtimeSession();
        const secret = String(realtimeSession?.client_secret || '').trim();
        if (!secret) throw new Error('Realtime voice session did not include a client secret.');

        realtimeLocalStream = await navigator.mediaDevices.getUserMedia({ audio: true });

        realtimePeerConnection = new RTCPeerConnection();
        realtimeRemoteAudio = new Audio();
        realtimeRemoteAudio.autoplay = true;
        realtimePeerConnection.ontrack = (event) => {
            const [stream] = event.streams;
            if (stream) realtimeRemoteAudio.srcObject = stream;
        };
        const [audioTrack] = realtimeLocalStream.getAudioTracks();
        if (!audioTrack) throw new Error('Microphone did not provide an audio track.');
        realtimePeerConnection.addTransceiver(audioTrack, { direction: 'sendrecv', streams: [realtimeLocalStream] });

        realtimeDataChannel = realtimePeerConnection.createDataChannel('oai-events');
        realtimeDataChannel.addEventListener('open', () => {
            realtimeSend(realtimeInstructionsUpdate());
            state.chatRunState = 'Listening for “Hey Bean”…';
            render();
        });
        realtimeDataChannel.addEventListener('message', (event) => handleRealtimeEvent(event));

        const offer = await realtimePeerConnection.createOffer();
        await realtimePeerConnection.setLocalDescription(offer);
        const model = encodeURIComponent(String(realtimeSession.model || 'gpt-realtime'));
        const baseUrl = String(realtimeSession.realtime_url || 'https://api.openai.com/v1/realtime').replace(/\?+$/, '');
        const sdpResponse = await fetch(`${baseUrl}?model=${model}`, {
            method: 'POST',
            headers: {
                Authorization: `Bearer ${secret}`,
                'Content-Type': 'application/sdp',
                Accept: 'application/sdp, application/json',
            },
            body: offer.sdp,
        });
        if (!sdpResponse.ok) {
            const errorBody = await sdpResponse.text().catch(() => '');
            let detail = '';
            try {
                detail = JSON.parse(errorBody)?.error?.message || '';
            } catch (_) {
                detail = errorBody.trim().slice(0, 220);
            }
            throw new Error(`Realtime voice connection failed (${sdpResponse.status})${detail ? `: ${detail}` : ''}.`);
        }
        await realtimePeerConnection.setRemoteDescription({ type: 'answer', sdp: await sdpResponse.text() });
    }

    function handleRealtimeEvent(event) {
        let payload = null;
        try {
            payload = JSON.parse(event.data);
        } catch (_) {
            return;
        }
        if (!payload?.type) return;

        if (payload.type === 'response.created') {
            realtimeResponseActive = true;
            realtimeResponseLifecycle.bindResponse(payload.response?.id);
            return;
        }

        if (payload.type === 'output_audio_buffer.started') {
            realtimeResponseActive = true;
            realtimeResponseLifecycle.markAudioStarted(payload.response_id);
            return;
        }

        if (payload.type === 'output_audio_buffer.stopped') {
            const trackedResponseActive = realtimeResponseLifecycle.isActive();
            const completedResponse = realtimeResponseLifecycle.markAudioStopped(payload.response_id);
            if (!trackedResponseActive || !completedResponse) return;
            realtimeResponseActive = false;
            realtimeIgnoreInputUntil = Date.now() + 400;
            realtimeSuppressNextAssistantTranscript = false;
            return;
        }

        if (payload.type === 'input_audio_buffer.speech_started') {
            if (Date.now() < realtimeIgnoreInputUntil || realtimeLaravelTurnInFlight) return;
            stopBeanVoicePlayback();
            state.chatRunState = realtimeWakeActivated || realtimeFollowUpActive() ? 'Listening…' : 'Listening for “Hey Bean”…';
            render();
            return;
        }

        if (payload.type === 'conversation.item.input_audio_transcription.completed') {
            const transcriptId = payload.item_id || payload.item?.id || '';
            if (!realtimeCallDeduper.claimTranscript(transcriptId)) return;
            handleRealtimeUserTranscript(payload.transcript || '').catch((error) => {
                state.error = friendlyError(error, 'handle realtime voice transcript');
                render();
            });
            return;
        }

        if (payload.type === 'response.audio_transcript.delta' || payload.type === 'response.text.delta' || payload.type === 'response.output_text.delta') {
            const delta = String(payload.delta || '');
            if (delta) {
                realtimeAssistantDraft += delta;
                state.chatRunState = 'Bean is speaking…';
                render();
            }
            return;
        }

        if (payload.type === 'response.audio_transcript.done' || payload.type === 'response.output_text.done') {
            const text = String(payload.transcript || payload.text || realtimeAssistantDraft || '').trim();
            realtimeResponseLifecycle.captureTranscript(text);
            realtimeAssistantDraft = '';
            return;
        }

        if (payload.type === 'response.function_call_arguments.delta') {
            const callId = payload.call_id || payload.item_id;
            if (callId) realtimeToolCalls.set(callId, `${realtimeToolCalls.get(callId) || ''}${payload.delta || ''}`);
            return;
        }

        if (payload.type === 'response.function_call_arguments.done') {
            handleRealtimeToolCall(payload).catch((error) => {
                state.error = friendlyError(error, 'run realtime voice tool');
                render();
            });
            return;
        }

        if (payload.type === 'response.done') {
            realtimeResponseLifecycle.captureTranscript(extractRealtimeResponseTranscript(payload.response));
            const trackedResponseActive = realtimeResponseLifecycle.isActive();
            const completedResponse = realtimeResponseLifecycle.markResponseDone(payload.response?.id);
            if (trackedResponseActive && !completedResponse) return;
            realtimeResponseActive = false;
            realtimeIgnoreInputUntil = Date.now() + 1200;
            realtimeSuppressNextAssistantTranscript = false;
            const output = normalizeList(payload.response?.output);
            output.forEach((item) => {
                if (item?.type === 'function_call') {
                    handleRealtimeToolCall(item).catch((error) => {
                        state.error = friendlyError(error, 'run realtime voice tool');
                        render();
                    });
                }
            });
            return;
        }

        if (payload.type === 'error') {
            const errorMessage = payload.error?.message || 'Realtime voice stopped unexpectedly.';
            if (/Cancellation failed: no active response found/i.test(errorMessage)) {
                return;
            }
            realtimeResponseLifecycle.cancel();
            state.error = errorMessage;
            render();
        }
    }

    async function handleRealtimeUserTranscript(transcript) {
        const text = String(transcript || '').trim();
        if (!text) return;
        if (isVoiceFillerOnly(stripVoiceWakeWords(text))) {
            realtimePendingTranscript = '';
            updateVoiceWakeDraft('');
            return;
        }
        realtimePendingTranscript = text;
        updateVoiceWakeDraft(text);
        if (voiceStopCommand(text)) {
            await stopVoiceConversationFromSpeech();
            return;
        }
        const now = Date.now();
        const heardWakeWord = voiceWakeWordPattern().test(text);
        const acceptsFollowUp = realtimeWakeActivated || realtimeFollowUpActive();
        if (!acceptsFollowUp && !heardWakeWord) return;

        const command = (heardWakeWord ? textAfterWakeWord(text) : text).trim();
        if (!command) {
            realtimeWakeActivated = true;
            state.chatRunState = 'Listening…';
            render();
            return;
        }
        if (now < realtimeIgnoreInputUntil || realtimeResponseActive || realtimeLaravelTurnInFlight || now < realtimeTurnGuardUntil || chatHasActiveTurn()) {
            queueRealtimeFollowUpTranscript(command);
            return;
        }

        const commandKey = normalizedVoiceCommand(command);
        if (commandKey && realtimeLastCommandKey === commandKey && now - realtimeLastCommandAt < 8000) return;
        realtimeLastCommandKey = commandKey;
        realtimeLastCommandAt = now;

        realtimeWakeActivated = true;
        clearRealtimeFollowUpWindow();
        const voiceStatusAnswer = realtimeVoiceStatusAnswer(command);
        if (voiceStatusAnswer) {
            state.chatRunState = 'Bean is speaking…';
            state.chatDraft = '';
            state.messages = state.messages.filter((message) => !message?.metadata?.realtime_draft);
            state.messages.push({ id: `realtime-user-${Date.now()}`, role: 'user', content: command, metadata: { realtime_voice_status: true } });
            render();
            await playBeanVoiceResponse(voiceStatusAnswer);
            state.messages.push({ id: `realtime-assistant-${Date.now() + 1}`, role: 'assistant', content: voiceStatusAnswer, metadata: { realtime_voice_status: true } });
            if (state.voiceWakeListening) {
                setRealtimeFollowUpWindow();
                state.chatRunState = 'Listening for follow-up…';
            }
            render();
            return;
        }

        const needsAppRuntime = realtimeNeedsAppRuntime(command, { appConversationActive: realtimeAppConversationActive });
        if (!needsAppRuntime) {
            state.chatRunState = 'Bean is speaking…';
            state.chatDraft = '';
            state.messages = state.messages.filter((message) => !message?.metadata?.realtime_draft);
            state.messages.push({ id: `realtime-user-${Date.now()}`, role: 'user', content: command, metadata: { realtime_direct: true } });
            render();
            const spoken = await speakRealtimeInstructions(realtimeDirectAnswerInstructions(command), { suppressTranscript: false, purpose: 'direct' });
            const directAnswer = String(spoken?.transcript || '').trim();
            if (directAnswer) {
                pushRealtimeAssistantMessage(directAnswer);
                persistRealtimeTurn(command, directAnswer).catch(() => {});
            }
            if (state.voiceWakeListening) {
                setRealtimeFollowUpWindow();
                state.chatRunState = 'Listening for follow-up…';
            }
            render();
            return;
        }

        realtimeAppConversationActive = true;
        realtimeTurnGuardUntil = now + 20000;
        realtimeLaravelTurnInFlight = true;
        state.chatRunState = realtimeWorkingStatus(command);
        state.chatDraft = '';
        state.messages = state.messages.filter((message) => !message?.metadata?.realtime_draft);
        render();
        await speakRealtimeInstructions(realtimeWorkingAckInstructions(command), { purpose: 'acknowledgement' });
        state.chatRunState = realtimeWorkingStatus(command);
        render();
        try {
            const result = await sendChatContent(command, { voiceRequest: true, deferAssistantMessage: true });
            if (result?.assistantContent) {
                state.chatRunState = 'Bean is speaking…';
                render();
                await playBeanVoiceResponse(result.assistantContent);
                revealDeferredAssistantMessage(result.assistantMessage, result.assistantContent);
                if (state.voiceWakeListening) {
                    setRealtimeFollowUpWindow();
                    state.chatRunState = 'Listening for follow-up…';
                }
                render();
            }
        } catch (error) {
            state.error = friendlyError(error, 'send realtime voice request');
            render();
        } finally {
            realtimeLaravelTurnInFlight = false;
            realtimeTurnGuardUntil = Date.now() + 400;
        }
    }

    function replaceOrAppendRealtimeUserMessage(text) {
        const content = String(text || '').trim();
        if (!content) return;
        const last = state.messages[state.messages.length - 1];
        if (last?.metadata?.realtime_draft && last.role === 'user') {
            last.content = content;
            return;
        }
        state.messages.push({ id: `realtime-user-${Date.now()}`, role: 'user', content, metadata: { realtime_draft: true } });
    }

    function pushRealtimeAssistantMessage(text) {
        const content = safeAssistantDisplayContent(conversationalMessageContent(String(text || '').trim()));
        if (!content) return;
        state.messages.forEach((message) => {
            if (message?.metadata?.realtime_draft) delete message.metadata.realtime_draft;
        });
        const last = state.messages[state.messages.length - 1];
        if (last?.role === 'assistant' && last?.metadata?.realtime_response) {
            last.content = content;
        } else {
            state.messages.push({ id: `realtime-assistant-${Date.now()}`, role: 'assistant', content, metadata: { realtime_response: true } });
        }
    }

    async function persistRealtimeTurn(userText, assistantText) {
        const user = String(userText || '').trim();
        const assistant = String(assistantText || '').trim();
        if (!state.session?.id || !user || !assistant) return;
        const persisted = await api('/assistant/voice/realtime/turn', {
            method: 'POST',
            body: {
                session_id: state.session.id,
                user_text: user,
                assistant_text: assistant,
                metadata: webChatMetadata({
                    client_request_id: `web-realtime-${Date.now()}`,
                }),
            },
        });
        state.session = persisted.session || state.session;
        if (persisted.user_message || persisted.assistant_message) {
            state.messages = state.messages.filter((message) => !message?.metadata?.realtime_response && !message?.metadata?.realtime_draft);
            if (persisted.user_message) state.messages.push(persisted.user_message);
            if (persisted.assistant_message) state.messages.push({
                ...persisted.assistant_message,
                content: safeAssistantDisplayContent(conversationalMessageContent(persisted.assistant_message.content || '')),
            });
            loadChatSessions({ resumeToday: false, shouldRender: false }).then(() => render()).catch(() => {});
        }
    }

    async function handleRealtimeToolCall(payload) {
        const callId = payload.call_id || payload.item_id;
        if (!realtimeCallDeduper.claimToolCall(callId)) return;
        if (realtimeLaravelTurnInFlight || chatHasActiveTurn()) {
            if (callId) {
                realtimeSend({
                    type: 'conversation.item.create',
                    item: {
                        type: 'function_call_output',
                        call_id: callId,
                        output: 'This request is already being handled by HeyBean.',
                    },
                });
            }
            return;
        }
        const name = payload.name || payload.function?.name || 'send_bean_request';
        const rawArguments = payload.arguments || realtimeToolCalls.get(callId) || '{}';
        if (callId) realtimeToolCalls.delete(callId);
        let args = {};
        try {
            args = JSON.parse(rawArguments || '{}');
        } catch (_) {
            args = { request: realtimePendingTranscript || rawArguments };
        }
        const approval = await api('/assistant/voice/realtime/tool', {
            method: 'POST',
            body: { name, arguments: args, session_id: state.session?.id || null },
        });
        const request = String(approval?.request || args.request || realtimePendingTranscript || '').trim();
        let output = 'I could not route that request.';
        let assistantMessage = null;
        if (request) {
            const result = await sendChatContent(request, { voiceRequest: true, deferAssistantMessage: true, realtimeToolRequest: true });
            output = result?.assistantContent || 'I sent that to HeyBean.';
            assistantMessage = result?.assistantMessage || null;
        }
        if (callId) {
            realtimeSend({
                type: 'conversation.item.create',
                item: {
                    type: 'function_call_output',
                    call_id: callId,
                    output,
                },
            });
        }
        await playBeanVoiceResponse(output);
        revealDeferredAssistantMessage(assistantMessage, output);
        if (state.voiceWakeListening) {
            setRealtimeFollowUpWindow();
            state.chatRunState = 'Listening for follow-up…';
        }
        render();
    }

    function resetRealtimeConversationToWakeMode(options = {}) {
        clearRealtimeFollowUpWindow();
        clearQueuedRealtimeFollowUp();
        realtimeWakeActivated = false;
        realtimePendingTranscript = '';
        realtimeAssistantDraft = '';
        realtimeLaravelTurnInFlight = false;
        realtimeSuppressNextAssistantTranscript = false;
        if (options.stopPlayback !== false) stopBeanVoicePlayback({ teardown: false });
        realtimeResponseActive = false;
        realtimeLastCommandKey = '';
        realtimeLastCommandAt = 0;
        realtimeTurnGuardUntil = Date.now() + (options.guardMs ?? 1200);
        realtimeIgnoreInputUntil = Date.now() + (options.guardMs ?? 1200);
        realtimeToolCalls.clear();
        realtimeCallDeduper.reset();
        realtimeResponseLifecycle.cancel();
        updateVoiceWakeDraft('');
        if (state.voiceWakeListening || realtimeVoiceActive) {
            state.voiceWakeListening = true;
            state.voiceProcessing = false;
            if (!state.busy) state.chatRunState = 'Listening for “Hey Bean”…';
            render();
        }
    }

    async function stopVoiceConversationFromSpeech() {
        resetRealtimeConversationToWakeMode({ stopPlayback: true, guardMs: 1500 });
        if (state.busy || activeChatRequestId) {
            await cancelBeanTurn();
            resetRealtimeConversationToWakeMode({ stopPlayback: false, guardMs: 1500 });
        }
    }

    function stopVoiceWakeListening(options = {}) {
        clearRealtimeFollowUpWindow();
        clearQueuedRealtimeFollowUp();
        realtimeWakeActivated = false;
        realtimePendingTranscript = '';
        realtimeAssistantDraft = '';
        realtimeLaravelTurnInFlight = false;
        realtimeSuppressNextAssistantTranscript = false;
        realtimeResponseActive = false;
        realtimeLastCommandKey = '';
        realtimeLastCommandAt = 0;
        realtimeTurnGuardUntil = 0;
        realtimeIgnoreInputUntil = 0;
        realtimeToolCalls.clear();
        realtimeCallDeduper.reset();
        realtimeResponseLifecycle.cancel();
        realtimeVoiceActive = false;
        state.voiceWakeListening = false;
        state.voiceProcessing = false;
        if (options.clearDraft !== false) updateVoiceWakeDraft('');
        try { realtimeDataChannel?.close?.(); } catch (_) {}
        try { realtimePeerConnection?.close?.(); } catch (_) {}
        realtimeLocalStream?.getTracks?.().forEach((track) => track.stop());
        realtimeDataChannel = null;
        realtimePeerConnection = null;
        realtimeLocalStream = null;
        realtimeSession = null;
        if (realtimeRemoteAudio) {
            try {
                realtimeRemoteAudio.pause();
                realtimeRemoteAudio.srcObject = null;
            } catch (_) {}
        }
        realtimeRemoteAudio = null;
        if (!state.busy) state.chatRunState = 'Ready';
    }

    async function startVoiceWakeListening(event) {
        event?.preventDefault?.();
        if (beanChatWaking() || state.voiceProcessing || realtimeVoiceActive) return;
        state.error = '';
        state.voiceProcessing = true;
        state.chatRunState = 'Starting realtime Bean voice…';
        render();
        try {
            await connectRealtimeVoice();
            realtimeVoiceActive = true;
            state.voiceWakeListening = true;
            state.voiceProcessing = false;
            state.chatRunState = 'Listening for “Hey Bean”…';
            updateVoiceWakeDraft('');
            render();
        } catch (error) {
            stopVoiceWakeListening({ clearDraft: false });
            state.error = friendlyError(error, 'start realtime Bean voice');
            state.voiceProcessing = false;
            render();
        }
    }

    function toggleVoiceWakeListening(event) {
        event?.preventDefault?.();
        if (state.voiceWakeListening || realtimeVoiceActive || state.voiceProcessing) {
            stopVoiceWakeListening();
            render();
            return;
        }
        startVoiceWakeListening(event);
    }

    async function submitChat(event) {
        event.preventDefault();
        if (beanChatWaking()) return;
        const form = event.currentTarget;
        const content = new FormData(form).get('message')?.toString().trim();
        if (!content) return;
        const editingMessageId = state.editingChatMessageId || '';
        if (chatHasActiveTurn()) {
            enqueueChatContent(content, editingMessageId ? { editingMessageId } : {});
            return;
        }
        state.editingChatMessageId = '';
        state.chatDraft = '';
        await sendChatContent(content, editingMessageId ? { editingMessageId } : {});
    }

    async function copyChatMessage(messageId) {
        const message = state.messages.find((item) => String(item.id) === String(messageId));
        const content = String(message?.content || '').trim();
        if (!content) return;
        try {
            await navigator.clipboard.writeText(content);
            state.notice = 'Message copied.';
        } catch (error) {
            state.error = 'Could not copy that message.';
        }
        render();
    }

    function editChatMessage(messageId) {
        if (chatHasActiveTurn()) return;
        const message = state.messages.find((item) => String(item.id) === String(messageId) && item.role === 'user');
        if (!message) return;
        state.editingChatMessageId = String(message.id);
        state.chatDraft = message.content || '';
        render();
        const textarea = mount.querySelector('textarea[name="message"]');
        if (textarea) {
            textarea.value = state.chatDraft;
            resizeChatInput(textarea);
            textarea.focus();
            textarea.selectionStart = textarea.selectionEnd = textarea.value.length;
        }
    }

    async function cancelBeanTurn(event = null) {
        event?.preventDefault?.();
        event?.stopPropagation?.();
        if (activeChatRequestId) {
            cancelledChatRequestIds.add(activeChatRequestId);
        }
        activeChatRequestId = 0;
        stopBeanVoicePlayback();
        stopBeanWorkEventPolling();
        state.busy = false;
        state.chatRunState = 'Ready';
        state.beanWorkItems = [];
        render();
        scrollChatToBottom();

        if (!state.session?.id) return;
        try {
            state.session = await api(`/assistant/sessions/${state.session.id}/cancel`, { method: 'POST' });
        } catch (error) {
            // A completed turn can race the cancel request; the UI has already been released.
        } finally {
            scheduleChatQueueDrain();
        }
    }

    async function sendChatContent(content, options = {}) {
        const requestId = ++chatRequestCounter;
        activeChatRequestId = requestId;
        const wasOnboarding = needsBeanOnboarding();
        const editingMessageId = options.editingMessageId ? String(options.editingMessageId) : '';
        const clientRequestId = `web-chat-${Date.now()}-${requestId}`;
        let result = null;
        let assistantContent = '';
        let assistantMessage = null;
        let needsAssistantLookup = false;

        if (options.autoOpenChat && state.selected !== 'bean') {
            state.selected = 'bean';
        }
        if (editingMessageId) {
            const editIndex = state.messages.findIndex((message) => String(message.id) === editingMessageId && message.role === 'user');
            if (editIndex >= 0) state.messages.splice(editIndex);
        }
        state.messages.push({ id: `local-${Date.now()}`, role: 'user', content });
        state.busy = true;
        state.chatDraft = '';
        state.editingChatMessageId = '';
        state.chatRunState = 'Thinking…';
        resetBeanWorkItems([]);
        state.error = '';
        render();
        if (options.voiceRequest) scheduleVoiceFiller(requestId);
        try {
            if (!state.session?.id) {
                const onboarding = needsBeanOnboarding();
                state.session = await api('/assistant/sessions', {
                    method: 'POST',
                    body: chatSessionPayload(onboarding),
                });
            }
            startBeanWorkEventPolling(state.session.id);
            const useRunEndpoint = !editingMessageId;
            const metadata = webChatMetadata({
                source: useRunEndpoint ? 'web_routed_chat' : 'web_direct_chat',
                client_request_id: clientRequestId,
                ...(editingMessageId ? { edited_message_id: editingMessageId } : {}),
                ...(options.voiceRequest ? { voice_request: true, requested_response_voice: state.user?.active_workspace_agent_profile?.settings?.voice?.voice || state.user?.activeWorkspaceAgentProfile?.settings?.voice?.voice || 'alloy' } : {}),
            });
            const path = useRunEndpoint
                ? `/assistant/sessions/${state.session.id}/runs`
                : editingMessageId
                ? `/assistant/sessions/${state.session.id}/messages/${encodeURIComponent(editingMessageId)}/branch`
                : `/assistant/sessions/${state.session.id}/messages`;
            const body = useRunEndpoint
                ? { content, source: 'web_routed_chat', metadata }
                : { content, metadata };
            result = await api(path, {
                method: 'POST',
                body,
            });
            if (cancelledChatRequestIds.has(requestId)) {
                state.session = result.session || state.session;
                state.activity = normalizeList(result.events).length ? result.events : state.activity;
                state.chatRunState = 'Ready';
                await refreshOnly(false);
                return { result, assistantContent: '', clientRequestId };
            }
            state.session = result.session || state.session;
            state.activity = normalizeList(result.events).length ? result.events : state.activity;
            if (result.user_message) {
                state.activeBeanWorkMessageId = Number(result.user_message.id || 0) || state.activeBeanWorkMessageId;
                replaceLocalUserMessage(result.user_message);
            }
            applyBeanWorkEvents(result.events);
            if (result.assistant_message) {
                assistantMessage = result.assistant_message;
                assistantContent = safeAssistantDisplayContent(conversationalMessageContent(result.assistant_message.content || ''));
                const visibleAssistantAdded = options.deferAssistantMessage
                    ? shouldDeferAssistantMessage(result.assistant_message, assistantContent, assistantMessageShouldStayOutOfChat)
                    : pushVisibleAssistantMessage(result.assistant_message, assistantContent);
                if (!visibleAssistantAdded) {
                    assistantContent = '';
                    assistantMessage = null;
                    needsAssistantLookup = true;
                    state.chatRunState = 'Working…';
                    ensurePendingBeanWorkItem();
                }
            }
            if (result.status === 'blocked' && isPlanLimitMessage(assistantContent) && !options.deferAssistantMessage) {
                state.error = assistantContent;
            }
            const pendingResult = ['queued', 'running', 'processing'].includes(String(result.status || '').toLowerCase());
            if (pendingResult) {
                needsAssistantLookup = true;
                state.chatRunState = 'Working…';
                ensurePendingBeanWorkItem();
            } else if (state.chatRunState !== 'Working…') {
                state.chatRunState = result.status === 'blocked' ? 'Blocked' : 'Ready';
            }
            if (!pendingResult) {
                await refreshOnly(false);
            }
            if (wasOnboarding && !needsBeanOnboarding()) {
                state.onboardingJustCompleted = true;
                startOnboardingTourIfNeeded();
            }
            loadChatSessions({ resumeToday: false, shouldRender: false }).then(() => render()).catch(() => {});
        } catch (error) {
            if (!cancelledChatRequestIds.has(requestId)) {
                const recovered = await recoverChatFailureFromServer({
                    sessionId: state.session?.id,
                    clientRequestId,
                    content,
                    requestId,
                });
                if (recovered) {
                    result = recovered.result;
                    assistantContent = recovered.assistantContent || '';
                    assistantMessage = recovered.result?.assistant_message || null;
                    if (options.deferAssistantMessage) removeDeferredAssistantMessage(assistantMessage);
                    return { ...recovered, assistantMessage, clientRequestId };
                }
                assistantContent = friendlyError(error, 'send that message');
                assistantMessage = { id: `error-${Date.now()}`, role: 'assistant', content: assistantContent, metadata: { voice_request: Boolean(options.voiceRequest) } };
                state.error = options.deferAssistantMessage ? '' : assistantContent;
                if (!options.deferAssistantMessage) state.messages.push(assistantMessage);
                state.chatRunState = 'Failed';
            }
        } finally {
            if (options.voiceRequest) clearVoiceFiller();
            cancelledChatRequestIds.delete(requestId);
            if (activeChatRequestId === requestId) {
                const shouldKeepPollingWork = result
                    && (needsAssistantLookup || ['queued', 'running', 'processing'].includes(String(result.status || '').toLowerCase()))
                    && state.session?.id;
                activeChatRequestId = shouldKeepPollingWork ? requestId : 0;
                state.busy = Boolean(shouldKeepPollingWork);
                if (shouldKeepPollingWork) {
                    startBeanWorkEventPolling(state.session.id, {
                        clientRequestId,
                        content,
                        voiceRequest: options.voiceRequest,
                    });
                } else {
                    stopBeanWorkEventPolling();
                    scheduleChatQueueDrain();
                }
            }
            if (state.beanWorkItems.length && state.beanWorkItems.every((item) => beanWorkItemDone(item))) {
                scheduleBeanWorkStatusClear();
            }
            render();
            scrollChatToBottom();
        }
        return { result, assistantContent, assistantMessage, clientRequestId };
    }

    async function recoverChatFailureFromServer({ sessionId, clientRequestId, content }) {
        const requestKey = String(clientRequestId || '').trim();
        if (!sessionId || !requestKey) return null;

        const applyCompletedSession = (sessionPayload) => {
            const messages = normalizeList(sessionPayload.messages);
            const userIndex = messages.findIndex((message) => {
                const metadata = message?.metadata || {};
                return message?.role === 'user' && String(metadata.client_request_id || '') === requestKey;
            });
            if (userIndex < 0) return null;

            const assistant = messages.slice(userIndex + 1).find((message) => {
                if (message?.role !== 'assistant') return false;
                const assistantContent = safeAssistantDisplayContent(conversationalMessageContent(message.content || ''));
                return !assistantMessageShouldStayOutOfChat({ ...message, content: assistantContent });
            }) || null;
            state.session = sessionPayload.session || sessionPayload;
            state.messages = messages;
            state.activity = normalizeList(sessionPayload.activity_events || sessionPayload.events).length
                ? normalizeList(sessionPayload.activity_events || sessionPayload.events)
                : state.activity;
            state.activeBeanWorkMessageId = Number(messages[userIndex]?.id || 0) || state.activeBeanWorkMessageId;
            if (assistant) {
                const assistantContent = safeAssistantDisplayContent(conversationalMessageContent(assistant.content || ''));
                assistant.content = assistantContent;
                state.chatRunState = 'Ready';
                completeActiveBeanWorkItems();
                refreshOnly(false).catch(() => {});
                return { result: { status: 'completed', session: state.session, user_message: messages[userIndex], assistant_message: assistant, events: [] }, assistantContent };
            }

            state.chatRunState = 'Working…';
            ensurePendingBeanWorkItem();
            return { result: { status: 'queued', session: state.session, user_message: messages[userIndex], assistant_message: null, events: [] }, assistantContent: '' };
        };

        try {
            const sessionPayload = await api(`/assistant/sessions/${sessionId}`);
            const recovered = applyCompletedSession(sessionPayload);
            if (recovered) return recovered;
        } catch (_) {
            // Fall through to run lookup; the request may have been queued.
        }

        try {
            const lookup = await api(`/assistant/sessions/${sessionId}/runs/lookup?client_request_id=${encodeURIComponent(requestKey)}`);
            state.session = lookup.session || state.session;
            state.activity = normalizeList(lookup.events).length ? lookup.events : state.activity;
            if (lookup.user_message) {
                state.activeBeanWorkMessageId = Number(lookup.user_message.id || 0) || state.activeBeanWorkMessageId;
                replaceLocalUserMessage(lookup.user_message);
            }
            applyBeanWorkEvents(lookup.events);
            if (lookup.status === 'queued') {
                state.chatRunState = 'Working…';
                ensurePendingBeanWorkItem();
                return { result: lookup, assistantContent: '' };
            }
            if (lookup.assistant_message) {
                const assistantContent = safeAssistantDisplayContent(conversationalMessageContent(lookup.assistant_message.content || ''));
                const assistant = {
                    ...lookup.assistant_message,
                    content: assistantContent,
                };
                if (assistantMessageShouldStayOutOfChat(assistant)) {
                    state.chatRunState = 'Working…';
                    ensurePendingBeanWorkItem();
                    return { result: { ...lookup, status: 'queued', assistant_message: null }, assistantContent: '' };
                }
                pushVisibleAssistantMessage(assistant, assistantContent);
                state.chatRunState = lookup.status === 'blocked' ? 'Blocked' : 'Ready';
                if (lookup.status === 'completed') completeActiveBeanWorkItems();
                refreshOnly(false).catch(() => {});
                return { result: lookup, assistantContent };
            }
        } catch (_) {
            return null;
        }

        return null;
    }

    function revealDeferredAssistantMessage(message, assistantContent) {
        const content = safeAssistantDisplayContent(conversationalMessageContent(assistantContent || message?.content || ''));
        if (!content) return;
        const assistant = message
            ? { ...message, content }
            : { id: `voice-assistant-${Date.now()}`, role: 'assistant', content, metadata: { voice_request: true } };
        const id = String(assistant.id || '');
        if (id && state.messages.some((item) => String(item?.id || '') === id)) return;
        pushVisibleAssistantMessage(assistant, content);
    }

    function removeDeferredAssistantMessage(message) {
        const id = String(message?.id || '');
        if (!id) return;
        state.messages = state.messages.filter((item) => String(item?.id || '') !== id);
    }

    function replaceLocalUserMessage(message) {
        const reversedIndex = [...state.messages].reverse().findIndex((item) => {
            if (item?.role !== 'user') return false;
            const id = String(item.id || '');
            const local = id.startsWith('local-');
            if (!local) return false;
            return true;
        });
        if (reversedIndex < 0) {
            const existingPersistedId = state.messages.some((item) => {
                return item?.role === 'user'
                    && String(item.id || '') === String(message?.id || '');
            });
            if (!existingPersistedId) state.messages.push(message);
            return;
        }
        const index = state.messages.length - 1 - reversedIndex;
        state.messages[index] = message;
    }

    async function newSession() {
        if (chatHasActiveTurn()) return;
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

    function timezoneOffsetLabel(offsetMinutes) {
        const sign = offsetMinutes < 0 ? '-' : '+';
        const absolute = Math.abs(offsetMinutes);
        const hours = String(Math.floor(absolute / 60)).padStart(2, '0');
        const minutes = String(absolute % 60).padStart(2, '0');
        return `${sign}${hours}:${minutes}`;
    }

    function clientTemporalContext() {
        const now = new Date();
        const offsetMinutes = -now.getTimezoneOffset();
        const offset = timezoneOffsetLabel(offsetMinutes);
        const localDate = dateOnly(now);
        const localTime = [
            String(now.getHours()).padStart(2, '0'),
            String(now.getMinutes()).padStart(2, '0'),
            String(now.getSeconds()).padStart(2, '0'),
        ].join(':');
        const parts = new Intl.DateTimeFormat(undefined, { timeZoneName: 'short' }).formatToParts(now);
        const timezoneName = parts.find((part) => part.type === 'timeZoneName')?.value || '';
        return {
            current_local_time: `${localDate}T${localTime}${offset}`,
            timezone: Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC',
            timezone_name: timezoneName,
            timezone_offset: offset,
            timezone_offset_minutes: offsetMinutes,
            local_date: localDate,
            local_time: localTime,
        };
    }

    function webChatMetadata(additional = {}) {
        return {
            client_context: clientTemporalContext(),
            ...additional,
        };
    }

    function chatSessionPayload(onboarding = needsBeanOnboarding()) {
        const today = dateOnly(new Date());
        return {
            title: onboarding ? 'Welcome to Bean' : `${monthDayLabel(today)} with Bean`,
            runtime_mode: onboarding ? 'onboarding' : 'chat',
            workspace_id: currentWorkspaceId() || null,
            metadata: webChatMetadata({ daily_date: today, source: 'web_chat' }),
        };
    }

    async function loadChatSessions(options = {}) {
        if (!state.token || state.phase !== 'signedIn') return;
        if (options.resumeToday) state.chatSessionHydrating = true;
        const params = new URLSearchParams({
            date: dateOnly(new Date()),
            timezone: Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC',
            limit: '30',
        });
        const workspaceId = currentWorkspaceId();
        if (workspaceId) params.set('workspace_id', workspaceId);

        try {
            const result = await api(`/assistant/sessions?${params.toString()}`);
            state.chatSessions = normalizeList(result.sessions);

            if (options.resumeToday && !state.busy) {
                const todaySession = result.today_session || result.todaySession || null;
                if (todaySession?.id) {
                    await resumeSession(todaySession.id, { keepHistoryOpen: true });
                }
            }

            if (options.shouldRender !== false) render();
        } finally {
            if (options.resumeToday) state.chatSessionHydrating = false;
        }
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
            const calendarPath = options.skipCalendarSync === false ? '/calendar-events' : '/calendar-events?skip_google_sync=1&skip_outlook_sync=1';
            const workspaceId = currentWorkspaceId();
            const [summary, tasks, pastTasks, reminders, calendar, noteFolders, notes, memoryItems, memorySummaries, memoryHistory, categories, googleStatus, outlookStatus] = await Promise.all([
                api(workspaceScopedPath('/today', workspaceId)),
                api(workspaceScopedPath('/tasks', workspaceId)),
                api(workspaceScopedPath('/tasks/past', workspaceId)),
                api(workspaceScopedPath('/reminders', workspaceId)),
                api(workspaceScopedPath(calendarPath, workspaceId)),
                api(workspaceScopedPath('/note-folders', workspaceId)),
                api(workspaceScopedPath('/notes', workspaceId)),
                api(workspaceScopedPath('/memory-items', workspaceId)),
                api(workspaceScopedPath('/memory-summaries', workspaceId)),
                api(workspaceScopedPath('/memory/request-history?limit=10', workspaceId)),
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
            state.memoryItems = normalizeList(memoryItems);
            state.memorySummaries = normalizeList(memorySummaries);
            state.memoryHistory = normalizeList(memoryHistory);
            state.categories = normalizeList(categories);
            state.googleStatus = googleStatus;
            state.outlookStatus = outlookStatus;
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

    function scheduleDashboardRealtimeRefresh(changes = [], options = {}) {
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

    async function loadAdminUsage(force = false) {
        if (!userIsAdmin() || (state.adminUsage && !force)) return;
        window.clearTimeout(adminRealtimeRefreshTimer);
        state.adminUsageLoading = true;
        state.error = '';
        render();
        try {
            const growthRange = encodeURIComponent(state.adminUserGrowthRange || 'last_30_days');
            const [usage, modelRegistry, hermesStatus, liveLookup, planLimits, coupons] = await Promise.all([
                api(`/admin/usage/summary?user_growth_range=${growthRange}`),
                api('/admin/settings/models'),
                api('/admin/hermes/status').catch((error) => ({
                    configured: false,
                    version: 'Unavailable',
                    error: friendlyError(error, 'check Hermes status'),
                })),
                api('/admin/live-lookup/providers'),
                api('/admin/plan-limits'),
                api('/admin/coupon-codes'),
            ]);
            state.adminUsage = usage;
            state.adminModelRegistry = modelRegistry;
            state.adminHermesStatus = hermesStatus;
            state.adminLiveLookup = liveLookup;
            state.adminPlanLimits = planLimits;
            state.adminCoupons = coupons;
        } catch (error) {
            state.error = friendlyError(error, 'load admin metrics');
        } finally {
            state.adminUsageLoading = false;
            render();
            scheduleAdminRealtimeRefresh();
        }
    }

    function scheduleAdminRealtimeRefresh() {
        window.clearTimeout(adminRealtimeRefreshTimer);
        adminRealtimeRefreshTimer = 0;
        if (!userIsAdmin() || state.phase !== 'signedIn' || state.selected !== 'admin') return;
        adminRealtimeRefreshTimer = window.setTimeout(() => {
            if (state.selected === 'admin' && !state.adminUsageLoading) {
                loadAdminUsage(true);
            }
        }, 30000);
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
                state.notice = `${label} sync pulled ${result.imported || 0} external event${(result.imported || 0) === 1 ? '' : 's'} into Bean. Local Bean events stay local.`;
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
        const overdue = itemOverdue(task, 'task');
        const dueLabel = task.due_at || task.dueAt ? formatDueTime(task.due_at || task.dueAt, { includeDate: overdue }) : '';
        if (task.category) parts.push(task.category);
        if (overdue) parts.push('overdue');
        if (dueLabel) parts.push(`Due ${dueLabel}`);
        if (taskIsRecurring(task)) parts.push(recurrenceSummary(task));
        return parts.join(' · ');
    }

    function criticalReminderSubtitle(reminder) {
        const parts = [];
        const overdue = itemOverdue(reminder, 'reminder');
        const dateLabel = reminderDateValue(reminder) ? formatDueTime(reminderDateValue(reminder), { includeDate: overdue }) : '';
        if (reminder.category) parts.push(reminder.category);
        if (overdue) parts.push('overdue');
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
        const dueValue = task.due_at || task.dueAt || '';
        return [
            dueValue ? formatDueTime(dueValue, { includeDate: itemOverdue(task, 'task') }) : '',
            recurrenceSummary(task),
        ].filter(Boolean).join(' · ');
    }

    function reminderSubtitle(reminder) {
        const bits = [];
        const dueValue = reminder.remind_at || reminder.due_at || reminder.dueAt || '';
        if (reminder.category) bits.push(reminder.category);
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
        const value = event?.all_day ?? event?.allDay ?? metadata.all_day ?? metadata.allDay;
        return value === true || value === 1 || ['true', '1', 'yes'].includes(String(value || '').toLowerCase());
    }

    function eventIntersectsDay(event, day) {
        const startValue = event.starts_at || event.startsAt;
        if (!startValue) return false;
        if (eventAllDay(event)) {
            const dayValue = dateOnly(day);
            const startDate = storedDateOnly(startValue);
            const endDate = allDayExclusiveEndDate(event, startDate);
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

    function allDayEndDateInputValue(item, fallbackStartDate) {
        const end = item?.ends_at || item?.endsAt;
        if (!end || !eventAllDay(item)) return fallbackStartDate;
        return allDayInclusiveEndDate(item) || fallbackStartDate;
    }

    function allDayInclusiveEndDate(event) {
        const startValue = event?.starts_at || event?.startsAt;
        const endValue = event?.ends_at || event?.endsAt;
        if (!endValue) return startValue ? storedDateOnly(startValue) : '';
        const endDate = storedDateOnly(endValue);
        if (allDayEndIsExclusiveMidnight(startValue, endValue)) {
            return dateOnly(addDays(endDate, -1));
        }
        return endDate;
    }

    function allDayExclusiveEndDate(event, fallbackStartDate = '') {
        const startValue = event?.starts_at || event?.startsAt;
        const endValue = event?.ends_at || event?.endsAt;
        const startDate = fallbackStartDate || (startValue ? storedDateOnly(startValue) : '');
        if (!endValue) return dateOnly(addDays(startDate, 1));
        const endDate = storedDateOnly(endValue);
        return allDayEndIsExclusiveMidnight(startValue, endValue) ? endDate : dateOnly(addDays(endDate, 1));
    }

    function allDayEndIsExclusiveMidnight(startValue, endValue) {
        if (!startValue || !endValue) return false;
        const startDate = storedDateOnly(startValue);
        const endDate = storedDateOnly(endValue);
        if (endDate <= startDate) return false;
        const endText = String(endValue);
        return /^\d{4}-\d{2}-\d{2}$/.test(endText)
            || /T00:00(?::00(?:\.0+)?)?(?:Z|[+-]\d{2}:?\d{2})?$/.test(endText);
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

    function adminServerStatusLabel(status) {
        return {
            healthy: 'Healthy',
            watch: 'Watch',
            critical: 'Upgrade soon',
        }[String(status || '').toLowerCase()] || 'Unknown';
    }

    function adminServerStatusMeta(server = {}) {
        const signals = normalizeList(server.signals);
        if (signals.length) return `${signals.length} signal${signals.length === 1 ? '' : 's'} to review`;
        const checkedAt = server.checked_at || server.checkedAt;
        return checkedAt ? `Checked ${formatTime(checkedAt)}` : 'No upgrade signals';
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
        return `${value}T23:59:00.000Z`;
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
        return {
            balanced: 'Balanced',
            coach: 'Coach',
            organizer: 'Organizer',
            creative: 'Creative',
            direct: 'Direct',
            gentle: 'Gentle',
        }[value] || 'Balanced';
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
            const scroller = document.getElementById('hb-chat-messages')
                || document.getElementById('hb-command-center-chat-messages');
            if (scroller) scroller.scrollTop = scroller.scrollHeight;
        });
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

    function scrollGuidedOnboardingThread() {
        window.requestAnimationFrame(() => {
            const thread = mount.querySelector('[data-guided-thread]') || mount.querySelector('.hb-guided-onboarding-thread');
            if (!thread) return;
            if (state.phase === 'subscription') {
                thread.scrollTop = 0;
                return;
            }
            thread.scrollTop = thread.scrollHeight;
            const input = mount.querySelector('.hb-guided-onboarding-input');
            input?.focus?.({ preventScroll: true });
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
        const gap = 18;
        const safeTop = 16;
        const dockHeight = viewportWidth <= 720 ? 90 : 96;
        const cardWidth = Math.min(420, viewportWidth - sideMargin * 2);
        card.style.width = `${cardWidth}px`;
        const cardHeight = card.offsetHeight || 196;
        const maxTop = Math.max(safeTop, viewportHeight - dockHeight - cardHeight - 16);
        const availableBelow = viewportHeight - dockHeight - bottom - 16;
        const availableAbove = top - safeTop;
        const preferredBelow = bottom + gap;
        const preferredAbove = top - cardHeight - gap;
        const cardTop = availableBelow >= cardHeight + gap
            ? preferredBelow
            : availableAbove >= cardHeight + gap
                ? preferredAbove
                : maxTop;
        const cardLeft = Math.min(
            Math.max(((left + right) / 2) - (cardWidth / 2), sideMargin),
            viewportWidth - sideMargin - cardWidth,
        );
        card.style.left = `${cardLeft}px`;
        card.style.top = `${Math.min(Math.max(cardTop, safeTop), maxTop)}px`;
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
        const message = error?.message || 'Something went wrong.';
        const safeMessage = safeBeanErrorMessage(message);
        if (safeMessage) return safeMessage;
        if (/failed to fetch/i.test(message)) return `Could not ${action}. Check your connection and try again.`;
        return message;
    }

    function safeBeanErrorMessage(message) {
        const text = String(message || '').trim();
        if (!text) return '';
        const normalized = text.toLowerCase().replace(/\s+/g, ' ');
        const staleFailurePhrases = [
            'bean could not finish',
            'could not finish that request',
            'work failed',
            'start that background work',
            'bean hit a snag starting',
        ];
        if (!staleFailurePhrases.some((phrase) => normalized.includes(phrase))) return '';
        return 'Bean is checking the latest app state now. If I need one more detail, I will ask.';
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
            || normalized.includes('available on premium')
            || normalized.includes('ai usage limit')
            || normalized.includes('external lookup usage limit');
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
