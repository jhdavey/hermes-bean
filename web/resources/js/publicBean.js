import { Conversation } from '@elevenlabs/client';
import '../css/public-bean.css';

const WAKE_GREETING = "Hey, I'm Bean, can you hear me?";
const IDLE_CLOSE_MS = 15000;
const WAKE_TO_GREETING_TARGET_MS = 1200;
let turnstileScriptPromise = null;

document.querySelectorAll('[data-public-bean]').forEach((root) => mountPublicBean(root));
mountTourImageZoom();

function mountTourImageZoom() {
    document.addEventListener('click', (event) => {
        const card = event.target?.closest?.('.tour-screenshot-card');
        if (!card) return;
        const image = card.querySelector('img');
        if (!image) return;
        event.preventDefault();
        openTourImageZoom(image);
    });

    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape') return;
        document.querySelector('.tour-image-zoom')?.remove();
    });
}

function openTourImageZoom(image) {
    document.querySelector('.tour-image-zoom')?.remove();
    const overlay = document.createElement('div');
    overlay.className = 'tour-image-zoom';
    overlay.setAttribute('role', 'dialog');
    overlay.setAttribute('aria-modal', 'true');
    overlay.setAttribute('aria-label', image.alt || 'HeyBean tour screenshot');
    const close = document.createElement('button');
    close.className = 'tour-image-zoom-close';
    close.type = 'button';
    close.setAttribute('aria-label', 'Close screenshot zoom');
    close.textContent = '×';
    const zoomedImage = document.createElement('img');
    zoomedImage.src = image.currentSrc || image.src;
    zoomedImage.alt = image.alt || '';
    overlay.append(close, zoomedImage);
    overlay.addEventListener('click', (event) => {
        if (event.target === overlay || event.target.closest('.tour-image-zoom-close')) overlay.remove();
    });
    document.body.appendChild(overlay);
    close.focus();
}

function mountPublicBean(root) {
    const button = root.querySelector('[data-public-bean-toggle]');
    const status = root.querySelector('[data-public-bean-status]');
    const cue = root.querySelector('[data-public-bean-cue]');
    if (!button || !status) return;

    // Public Bean is intentionally opt-in on every page load.
    let enabled = false;
    let conversation = null;
    let starting = false;
    let voiceActive = false;
    let idleTimer = 0;
    let lastActivityAt = 0;
    let lifecycleRevision = 0;
    let lastVoiceMode = '';
    let requestedIntent = '';

    let landingVoiceClientSessionId = '';
    let landingVoiceStartedAtMs = 0;
    let landingVoiceCloseLogged = true;
    let landingWakeDetectedAtMs = 0;
    let landingWakeToFirstSpeechMs = null;
    let landingHermesRuntimeSamplesMs = [];

    const isCurrentLifecycle = (revision) => enabled && lifecycleRevision === revision;

    const updateScrolledCueState = () => {
        root.dataset.scrolled = window.scrollY > 80 ? 'true' : 'false';
    };

    updateScrolledCueState();
    window.addEventListener('scroll', updateScrolledCueState, { passive: true });

    const setStatus = (mode, text) => {
        root.dataset.mode = mode;
        status.textContent = text;
        status.title = text;
        button.setAttribute('aria-pressed', enabled ? 'true' : 'false');
        button.setAttribute('aria-label', enabled ? 'End landing page Bean' : 'Talk with landing page Bean');
    };

    const stopIdleTimer = () => {
        window.clearTimeout(idleTimer);
        idleTimer = 0;
    };

    const scheduleIdleClose = () => {
        stopIdleTimer();
        if (!voiceActive) return;
        const scheduledAt = Date.now();
        idleTimer = window.setTimeout(() => {
            const elapsed = Date.now() - Math.max(lastActivityAt, scheduledAt);
            if (elapsed < IDLE_CLOSE_MS) {
                scheduleIdleClose();
                return;
            }
            stopVoiceConversation('client_idle_timeout').finally(() => {
                enabled = false;
                setStatus('disabled', 'Tap to talk');
            });
        }, IDLE_CLOSE_MS);
    };

    const requestVoiceSession = async () => {
        const turnstileToken = await getTurnstileToken(root);
        return postJson(root.dataset.conversationTokenUrl, {
            client_timezone: Intl.DateTimeFormat().resolvedOptions().timeZone || null,
            page_path: window.location.pathname,
            turnstile_token: turnstileToken,
        });
    };


    const stopVoiceConversation = async (reason = 'client_stop') => {
        stopIdleTimer();
        const activeConversation = conversation;
        if (voiceActive || activeConversation) logLandingVoiceClosed(reason);
        voiceActive = false;
        lastVoiceMode = '';
        conversation = null;
        await activeConversation?.endSession?.().catch(() => {});
    };

    const disable = async () => {
        lifecycleRevision += 1;
        enabled = false;
        starting = false;
        setStatus('disabled', 'Tap to talk');
        await stopVoiceConversation('disabled');
    };

    const normalizeLandingIntent = (intent) => {
        const key = String(intent || '').toLowerCase().trim().replace(/[\s-]+/g, '_');
        return ['quick_tour', 'question', 'signup'].includes(key) ? key : '';
    };

    const intentStatusText = (intent) => ({
        quick_tour: 'Starting quick tour…',
        question: 'Ask Bean anything…',
        signup: 'Starting signup help…',
    }[intent] || 'Turn volume on. Allow mic.');

    const sendIntentToActiveBean = (intent) => {
        if (!voiceActive || !conversation?.sendUserMessage) return false;
        const prompt = {
            quick_tour: 'Please give me the quick three-stop tour.',
            question: 'I have a question about HeyBean.',
            signup: 'Please help me sign up for HeyBean.',
        }[intent];
        if (!prompt) return false;
        conversation.sendUserMessage(prompt);
        conversation.sendUserActivity?.();
        lastActivityAt = Date.now();
        setStatus('thinking', 'Thinking…');
        return true;
    };

    const enable = async (intent = '') => {
        if (starting) return;
        requestedIntent = normalizeLandingIntent(intent);
        const revision = ++lifecycleRevision;
        starting = true;
        enabled = true;
        landingWakeDetectedAtMs = Date.now();
        landingWakeToFirstSpeechMs = null;
        setStatus('starting', intentStatusText(requestedIntent));
        let micAllowed = false;
        try {
            if (!navigator.mediaDevices?.getUserMedia) throw new Error('Microphone is unavailable.');
            const permissionStream = await navigator.mediaDevices.getUserMedia({ audio: true });
            permissionStream.getTracks().forEach((track) => track.stop());
            micAllowed = true;
            if (!isCurrentLifecycle(revision)) return;
            starting = false;
            setStatus('connecting', 'Connecting Bean…');
            await startVoiceConversation(revision);
        } catch (error) {
            if (!isCurrentLifecycle(revision)) return;
            await stopVoiceConversation('connect_error');
            enabled = false;
            starting = false;
            lifecycleRevision += 1;
            if (error?.status === 429) {
                setStatus('error', 'Demo cooldown — try again shortly');
            } else {
                setStatus('error', micAllowed ? 'Bean could not connect' : 'Tap to allow microphone');
            }
        } finally {
            if (lifecycleRevision === revision) starting = false;
        }
    };


    function newLandingVoiceEventId(prefix = 'landing-voice') {
        return `${prefix}-${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 10)}`;
    }

    function conversationIdentifier(activeConversation) {
        const candidate = activeConversation?.conversationId
            || activeConversation?.conversation_id
            || activeConversation?.id
            || activeConversation?.conversation?.id;

        return typeof candidate === 'string' && candidate.trim() ? candidate.trim() : null;
    }

    function logLandingVoiceEvent(eventType, payload = {}, options = {}) {
        const url = root.dataset.voiceEventUrl;
        if (!url || !landingVoiceClientSessionId) return;
        const occurredAtMs = Number(options.occurredAtMs) || Date.now();
        fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            keepalive: options.keepalive === true,
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': root.dataset.csrfToken,
            },
            body: JSON.stringify({
                event_type: eventType,
                client_session_id: landingVoiceClientSessionId,
                page_path: window.location.pathname,
                source: 'landing_page',
                payload: {
                    ...payload,
                    transport: 'elevenlabs_agent',
                    event_client_ms: occurredAtMs,
                    voice_active: Boolean(voiceActive),
                    voice_mode: lastVoiceMode || null,
                },
                occurred_at: new Date(occurredAtMs).toISOString(),
                occurred_at_ms: occurredAtMs,
            }),
        }).catch(() => {});
    }

    function logLandingVoiceClosed(reason = 'client_stop') {
        if (!landingVoiceClientSessionId || landingVoiceCloseLogged) return;
        landingVoiceCloseLogged = true;
        logLandingVoiceEvent('voice_session_closed', {
            reason,
            conversation_id: conversationIdentifier(conversation),
            started_event_client_ms: landingVoiceStartedAtMs || null,
            wake_event_client_ms: landingWakeDetectedAtMs || null,
            wake_to_first_speech_ms: landingWakeToFirstSpeechMs,
            wake_to_first_speech_target_ms: WAKE_TO_GREETING_TARGET_MS,
            wake_to_first_speech_target_met: landingWakeToFirstSpeechMs !== null
                ? landingWakeToFirstSpeechMs <= WAKE_TO_GREETING_TARGET_MS
                : null,
            hermes_runtime_samples_ms: landingHermesRuntimeSamplesMs,
        }, { keepalive: true });
    }

    async function startVoiceConversation(revision) {
        const session = await requestVoiceSession();
        if (!isCurrentLifecycle(revision)) return;
        if (!session?.token) throw new Error('Bean voice did not return a token.');

        landingVoiceClientSessionId = newLandingVoiceEventId();
        landingVoiceStartedAtMs = 0;
        landingVoiceCloseLogged = false;
        landingHermesRuntimeSamplesMs = [];
        const voiceClientSessionId = landingVoiceClientSessionId;
        logLandingVoiceEvent('voice_start_requested', {
            label: 'tap_to_start',
            requested_intent: requestedIntent || null,
            wake_event_client_ms: landingWakeDetectedAtMs,
        }, { occurredAtMs: landingWakeDetectedAtMs });

        const nextConversation = await Conversation.startSession({
            conversationToken: session.token,
            connectionType: 'webrtc',
            textOnly: false,
            useWakeLock: true,
            userId: session.landing_session_id ? `bean-visitor-${session.landing_session_id}` : undefined,
            overrides: {
                agent: {
                    firstMessage: WAKE_GREETING,
                },
            },
            dynamicVariables: {
                bean_landing_page: window.location.pathname,
                bean_client_timezone: Intl.DateTimeFormat().resolvedOptions().timeZone || '',
                bean_landing_intent: requestedIntent,
            },
            clientTools: {
                showLandingSection: async (parameters = {}) => {
                    showLandingUiAction(parameters.destination || parameters.section || parameters.action);
                    return 'Section shown.';
                },
                // Backward compatibility while the hosted Landing Guide agent is
                // being updated from the older Hermes-backed public voice flow.
                askLandingBean: async (parameters = {}) => {
                    const content = String(parameters.message || parameters.content || '').trim();
                    if (!content) return 'I did not hear a complete question.';
                    setStatus('thinking', 'Thinking…');
                    lastActivityAt = Date.now();
                    try {
                        const response = await postJson(root.dataset.messageUrl, {
                            content,
                            page_path: window.location.pathname,
                        });
                        const runtimeMs = Number(response?.runtime_ms);
                        if (Number.isFinite(runtimeMs) && runtimeMs >= 0) {
                            landingHermesRuntimeSamplesMs.push(Math.round(runtimeMs));
                        }
                        showLandingUiAction(response?.ui_action || parameters.destination);
                        return String(response?.answer || 'I am having trouble answering right now.');
                    } catch (error) {
                        return String(error?.publicMessage || 'I am having trouble answering right now.');
                    }
                },
            },
            onConnect: () => {
                if (!isCurrentLifecycle(revision)) return;
                voiceActive = true;
                lastVoiceMode = '';
                landingVoiceStartedAtMs = Date.now();
                landingVoiceCloseLogged = false;
                lastActivityAt = Date.now();
                logLandingVoiceEvent('voice_session_started', {
                    start_method: 'tap',
                    wake_event_client_ms: landingWakeDetectedAtMs || null,
                    conversation_id: conversationIdentifier(conversation),
                });
                setStatus('listening', 'Listening…');
            },
            onDisconnect: (details) => {
                if (!isCurrentLifecycle(revision)) return;
                if (landingVoiceClientSessionId !== voiceClientSessionId) return;
                logLandingVoiceClosed(details?.reason || 'provider_disconnect');
                voiceActive = false;
                conversation = null;
                lastVoiceMode = '';
                stopIdleTimer();
                landingVoiceClientSessionId = '';
                landingVoiceStartedAtMs = 0;
                landingWakeDetectedAtMs = 0;
                landingWakeToFirstSpeechMs = null;
                landingHermesRuntimeSamplesMs = [];
                enabled = false;
                setStatus('disabled', 'Tap to talk');
            },
            onError: () => {
                if (!isCurrentLifecycle(revision)) return;
                setStatus('error', 'Bean voice hit a problem');
            },
            onMessage: (message = {}) => {
                if (!isCurrentLifecycle(revision)) return;
                const role = String(message.role || message.source || '').toLowerCase();
                const content = String(message.message || message.text || '').trim();
                if (!content) return;
                lastActivityAt = Date.now();
                if (role === 'agent' || role === 'ai') {
                    setStatus('speaking', 'Speaking…');
                } else if (role === 'user') {
                    setStatus('thinking', 'Thinking…');
                }
            },
            onModeChange: ({ mode } = {}) => {
                if (!isCurrentLifecycle(revision)) return;
                const nextMode = String(mode || '').toLowerCase();
                lastVoiceMode = nextMode;
                lastActivityAt = Date.now();
                if (nextMode === 'speaking') {
                    stopIdleTimer();
                    setStatus('speaking', 'Speaking…');
                    if (landingWakeToFirstSpeechMs === null && landingWakeDetectedAtMs > 0) {
                        landingWakeToFirstSpeechMs = Date.now() - landingWakeDetectedAtMs;
                        logLandingVoiceEvent('assistant_speech_started', {
                            wake_event_client_ms: landingWakeDetectedAtMs,
                            wake_to_first_speech_ms: landingWakeToFirstSpeechMs,
                            target_ms: WAKE_TO_GREETING_TARGET_MS,
                            target_met: landingWakeToFirstSpeechMs <= WAKE_TO_GREETING_TARGET_MS,
                        });
                    }
                } else if (nextMode === 'listening') {
                    setStatus('listening', 'Listening…');
                    scheduleIdleClose();
                }
            },
        });
        if (!isCurrentLifecycle(revision)) {
            await nextConversation?.endSession?.().catch(() => {});
            return;
        }
        conversation = nextConversation;
        voiceActive = true;
        lastActivityAt = Date.now();
        if (lastVoiceMode !== 'speaking') {
            setStatus('connecting', 'Bean is joining…');
        }
        conversation.sendUserActivity?.();
    }

    button.addEventListener('click', () => {
        if (enabled) disable();
        else enable();
    });

    cue?.addEventListener('click', () => {
        if (enabled) disable();
        else enable();
    });

    root.querySelectorAll('[data-public-bean-intent]').forEach((intentButton) => {
        intentButton.addEventListener('click', () => {
            const intent = normalizeLandingIntent(intentButton.dataset.publicBeanIntent);
            if (enabled) {
                if (!sendIntentToActiveBean(intent)) disable();
                return;
            }
            enable(intent);
        });
    });

    window.addEventListener('pagehide', () => {
        stopVoiceConversation('pagehide');
    }, { once: true });

    setStatus('disabled', 'Tap to talk');

    async function postJson(url, body) {
        const response = await fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': root.dataset.csrfToken,
            },
            body: JSON.stringify(body),
        });
        const payload = await response.json().catch(() => ({}));
        if (!response.ok) {
            const error = new Error(payload.message || 'Bean request failed.');
            error.status = response.status;
            error.publicMessage = payload.message;
            throw error;
        }
        return payload.data || payload;
    }
}

function showLandingUiAction(action) {
    const targets = {
        how_it_works: { selector: '#how-it-works', href: '/#how-it-works', label: 'how it works', offset: 118 },
        bean: { selector: '#tour-command-center', href: '/#tour-command-center', label: 'command center with Bean', offset: 118 },
        command_center: { selector: '#tour-command-center', href: '/#tour-command-center', label: 'command center with Bean', offset: 118 },
        calendar_tasks: { selector: '#tour-calendar-tasks', href: '/#tour-calendar-tasks', label: 'calendar and tasks', offset: 118 },
        calendar: { selector: '#tour-calendar-tasks', href: '/#tour-calendar-tasks', label: 'calendar and tasks', offset: 118 },
        tasks: { selector: '#tour-calendar-tasks', href: '/#tour-calendar-tasks', label: 'calendar and tasks', offset: 118 },
        customization: { selector: '#tour-customization', href: '/#tour-customization', label: 'dashboard customization', offset: 118 },
        dashboard: { selector: '#tour-customization', href: '/#tour-customization', label: 'dashboard customization', offset: 118 },
        themes: { selector: '#tour-customization', href: '/#tour-customization', label: 'dashboard customization', offset: 118 },
        features: { selector: '#features', href: '/#features', label: 'features', offset: 118 },
        pricing: { selector: '#plans', scrollSelector: '#plans .plans', href: '/#plans', label: 'pricing', offset: 24 },
        signup: { selector: '#early-access', href: '/#early-access', label: 'early access', offset: 118 },
        onboarding: { selector: '#early-access', href: '/register?from=bean', label: 'Bean-guided onboarding', offset: 118, navigateDelay: 2800 },
    };
    const key = String(action || '').toLowerCase().trim().replace(/[\s-]+/g, '_');
    const target = targets[key];
    if (!target) return null;

    const section = document.querySelector(target.selector);
    if (!section) return null;
    const scrollTarget = target.scrollSelector ? (document.querySelector(target.scrollSelector) || section) : section;

    const reduceMotion = window.matchMedia?.('(prefers-reduced-motion: reduce)')?.matches === true;
    const top = scrollTarget.getBoundingClientRect().top + window.scrollY - (target.offset || 0);
    window.scrollTo({ top: Math.max(0, top), behavior: reduceMotion ? 'auto' : 'smooth' });
    const cue = scrollTarget.querySelector?.('.feature-copy, .section-head') || scrollTarget;
    cue.classList.remove('public-bean-guided-highlight');
    scrollTarget.classList.remove('public-bean-guided-highlight');
    window.requestAnimationFrame(() => {
        cue.classList.add('public-bean-guided-highlight');
        scrollTarget.classList.add('public-bean-guided-highlight');
    });
    window.setTimeout(() => {
        cue.classList.remove('public-bean-guided-highlight');
        scrollTarget.classList.remove('public-bean-guided-highlight');
    }, 2400);

    if (target.navigateDelay && target.href) {
        window.setTimeout(() => {
            window.location.href = target.href;
        }, target.navigateDelay);
    }

    return null;
}

async function getTurnstileToken(root) {
    const siteKey = String(root.dataset.turnstileSiteKey || '').trim();
    if (!siteKey) return null;

    await loadTurnstile();
    const container = root.querySelector('[data-public-bean-turnstile]');
    if (!container || !window.turnstile) throw new Error('Human verification is unavailable.');

    return new Promise((resolve, reject) => {
        container.hidden = false;
        let widgetId = null;
        widgetId = window.turnstile.render(container, {
            sitekey: siteKey,
            execution: 'execute',
            appearance: 'interaction-only',
            callback: (token) => {
                container.hidden = true;
                if (widgetId !== null) window.turnstile.remove(widgetId);
                resolve(token);
            },
            'error-callback': () => {
                container.hidden = true;
                if (widgetId !== null) window.turnstile.remove(widgetId);
                reject(new Error('Human verification failed.'));
            },
            'expired-callback': () => {
                container.hidden = true;
                if (widgetId !== null) window.turnstile.remove(widgetId);
                reject(new Error('Human verification expired.'));
            },
        });
        window.turnstile.execute(widgetId);
    });
}

function loadTurnstile() {
    if (window.turnstile) return Promise.resolve();
    if (turnstileScriptPromise) return turnstileScriptPromise;

    turnstileScriptPromise = new Promise((resolve, reject) => {
        const script = document.createElement('script');
        script.src = 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit';
        script.async = true;
        script.defer = true;
        script.onload = resolve;
        script.onerror = () => reject(new Error('Human verification could not load.'));
        document.head.appendChild(script);
    });

    return turnstileScriptPromise;
}
