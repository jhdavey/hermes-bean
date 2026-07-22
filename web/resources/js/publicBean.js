import { Conversation } from '@elevenlabs/client';
import '../css/public-bean.css';

const WAKE_PHRASE = 'Hey Bean';
const WAKE_GREETING = 'Hi, I’m Bean, the voice assistant inside HeyBean. I can show you how it works, walk through features or pricing, or give you a quick tour. What would you like?';
const IDLE_CLOSE_MS = 9000;
const SESSION_PREFETCH_TTL_MS = 120000;
const WAKE_SETTLE_MS = 160;
const WAKE_TO_GREETING_TARGET_MS = 1200;
let turnstileScriptPromise = null;

document.querySelectorAll('[data-public-bean]').forEach((root) => mountPublicBean(root));

function mountPublicBean(root) {
    const button = root.querySelector('[data-public-bean-toggle]');
    const status = root.querySelector('[data-public-bean-status]');
    if (!button || !status) return;

    // Public Bean is intentionally opt-in on every page load.
    let enabled = false;
    let wakeDetector = null;
    let conversation = null;
    let starting = false;
    let voiceActive = false;
    let idleTimer = 0;
    let lastActivityAt = 0;
    let lifecycleRevision = 0;
    let lastVoiceMode = '';
    let prefetchedSessionPromise = null;
    let prefetchedSessionAt = 0;
    let landingVoiceClientSessionId = '';
    let landingVoiceStartedAtMs = 0;
    let landingVoiceCloseLogged = true;
    let landingWakeDetectedAtMs = 0;
    let landingWakeToFirstSpeechMs = null;
    let landingHermesRuntimeSamplesMs = [];

    const isCurrentLifecycle = (revision) => enabled && lifecycleRevision === revision;

    const setStatus = (mode, text) => {
        root.dataset.mode = mode;
        status.textContent = text;
        status.title = text;
        button.setAttribute('aria-pressed', enabled ? 'true' : 'false');
        button.setAttribute('aria-label', enabled ? 'Disable landing page Bean' : 'Enable landing page Bean');
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
            stopVoiceConversation('client_idle_timeout').finally(() => restartWakeListening());
        }, IDLE_CLOSE_MS);
    };

    const stopWakeListening = () => {
        wakeDetector?.stop?.();
        wakeDetector = null;
    };

    const clearPrefetchedSession = () => {
        prefetchedSessionPromise = null;
        prefetchedSessionAt = 0;
    };

    const requestVoiceSession = async () => {
        const turnstileToken = await getTurnstileToken(root);
        return postJson(root.dataset.conversationTokenUrl, {
            client_timezone: Intl.DateTimeFormat().resolvedOptions().timeZone || null,
            page_path: window.location.pathname,
            turnstile_token: turnstileToken,
        });
    };

    const prefetchVoiceSession = () => {
        const fresh = prefetchedSessionPromise && Date.now() - prefetchedSessionAt < SESSION_PREFETCH_TTL_MS;
        if (fresh) return prefetchedSessionPromise;
        clearPrefetchedSession();
        prefetchedSessionAt = Date.now();
        prefetchedSessionPromise = requestVoiceSession().catch((error) => {
            clearPrefetchedSession();
            throw error;
        });
        return prefetchedSessionPromise;
    };

    const takeVoiceSession = async () => {
        const fresh = prefetchedSessionPromise && Date.now() - prefetchedSessionAt < SESSION_PREFETCH_TTL_MS;
        const sessionPromise = fresh ? prefetchedSessionPromise : requestVoiceSession();
        clearPrefetchedSession();
        return sessionPromise;
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
        setStatus('disabled', 'Tap to enable');
        stopWakeListening();
        clearPrefetchedSession();
        await stopVoiceConversation('disabled');
    };

    const restartWakeListening = async () => {
        if (!enabled || starting || wakeDetector || voiceActive) return;
        const revision = lifecycleRevision;
        let detector = null;
        try {
            setStatus('starting', 'Starting microphone…');
            detector = await createWakeDetector(WAKE_PHRASE, handleWake, () => {
                if (!isCurrentLifecycle(revision)) return;
                wakeDetector = null;
                setStatus('error', 'Microphone permission needed');
            });
            if (!isCurrentLifecycle(revision)) {
                detector.stop?.();
                return;
            }
            wakeDetector = detector;
            await detector.start();
            if (!isCurrentLifecycle(revision)) {
                if (wakeDetector === detector) wakeDetector = null;
                detector.stop?.();
                return;
            }
            setStatus('wake_listening', 'Just say “Hey Bean…”');
        } catch (_) {
            if (wakeDetector === detector) wakeDetector = null;
            detector?.stop?.();
            if (isCurrentLifecycle(revision)) setStatus('error', 'Wake word unavailable');
        }
    };

    const enable = async () => {
        if (starting) return;
        const revision = ++lifecycleRevision;
        starting = true;
        enabled = true;
        setStatus('starting', 'Enabling microphone…');
        try {
            if (!navigator.mediaDevices?.getUserMedia) throw new Error('Microphone is unavailable.');
            const permissionStream = await navigator.mediaDevices.getUserMedia({ audio: true });
            permissionStream.getTracks().forEach((track) => track.stop());
            if (!isCurrentLifecycle(revision)) return;
            starting = false;
            prefetchVoiceSession().catch(() => {});
            await restartWakeListening();
        } catch (_) {
            if (!isCurrentLifecycle(revision)) return;
            enabled = false;
            starting = false;
            lifecycleRevision += 1;
            setStatus('error', 'Tap to allow microphone');
        } finally {
            if (lifecycleRevision === revision) starting = false;
        }
    };

    async function handleWake(event = {}) {
        if (!enabled || voiceActive || starting) return;
        const revision = lifecycleRevision;
        stopWakeListening();
        const tail = String(event.tail || extractWakeTail(event.transcript || '', WAKE_PHRASE)).trim();
        landingWakeDetectedAtMs = Number(event.detectedAtMs) || Date.now();
        landingWakeToFirstSpeechMs = null;
        setStatus('connecting', 'Hey Bean heard…');
        try {
            await startVoiceConversation(tail, revision);
        } catch (error) {
            if (!isCurrentLifecycle(revision)) return;
            await stopVoiceConversation('connect_error');
            setStatus('error', error?.status === 429 ? 'Demo limit reached' : 'Bean could not connect');
            window.setTimeout(() => restartWakeListening(), 1500);
        }
    }

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

    async function startVoiceConversation(wakeTail, revision) {
        const session = await takeVoiceSession();
        if (!isCurrentLifecycle(revision)) return;
        if (!session?.token) throw new Error('Bean voice did not return a token.');

        landingVoiceClientSessionId = newLandingVoiceEventId();
        landingVoiceStartedAtMs = 0;
        landingVoiceCloseLogged = false;
        landingHermesRuntimeSamplesMs = [];
        const voiceClientSessionId = landingVoiceClientSessionId;
        logLandingVoiceEvent('wake_detected', {
            label: WAKE_PHRASE,
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
                    firstMessage: wakeTail ? '' : WAKE_GREETING,
                },
            },
            dynamicVariables: {
                bean_landing_page: window.location.pathname,
                bean_client_timezone: Intl.DateTimeFormat().resolvedOptions().timeZone || '',
            },
            clientTools: {
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
                    has_wake_tail: Boolean(wakeTail),
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
                prefetchVoiceSession().catch(() => {});
                restartWakeListening();
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
        if (wakeTail) {
            setStatus('thinking', 'Thinking…');
        } else if (lastVoiceMode !== 'speaking') {
            setStatus('connecting', 'Bean is joining…');
        }
        if (wakeTail) conversation.sendUserMessage?.(wakeTail);
        conversation.sendUserActivity?.();
    }

    button.addEventListener('click', () => {
        if (enabled) disable();
        else enable();
    });

    window.addEventListener('pagehide', () => {
        stopWakeListening();
        stopVoiceConversation('pagehide');
    }, { once: true });

    setStatus('disabled', 'Tap to enable');

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
        features: { selector: '#features', href: '/#features', label: 'features' },
        pricing: { selector: '#plans', href: '/#plans', label: 'pricing' },
    };
    const target = targets[String(action || '').toLowerCase()];
    if (!target) return null;

    const section = document.querySelector(target.selector);
    if (!section) return null;

    const reduceMotion = window.matchMedia?.('(prefers-reduced-motion: reduce)')?.matches === true;
    section.scrollIntoView({ behavior: reduceMotion ? 'auto' : 'smooth', block: 'start' });
    const cue = section.querySelector('.feature-copy, .section-head') || section;
    cue.classList.remove('public-bean-guided-highlight');
    window.requestAnimationFrame(() => cue.classList.add('public-bean-guided-highlight'));
    window.setTimeout(() => cue.classList.remove('public-bean-guided-highlight'), 2400);

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

async function createWakeDetector(phrase, onWake, onError) {
    if (window.HeyBeanLocalWakeDetector?.create) {
        return window.HeyBeanLocalWakeDetector.create({ phrase, onWake });
    }

    const Recognition = window.SpeechRecognition;
    if (!Recognition || typeof Recognition.available !== 'function') {
        throw new Error('Local speech recognition is unavailable.');
    }
    const localOptions = { langs: ['en-US'], processLocally: true };
    let availability = await Recognition.available(localOptions).catch(() => 'unavailable');
    if (availability === 'downloadable' && typeof Recognition.install === 'function') {
        await Recognition.install(localOptions).catch(() => false);
        availability = await Recognition.available(localOptions).catch(() => 'unavailable');
    }
    if (availability !== 'available') throw new Error('Local speech recognition is unavailable.');
    const normalizedPhrase = normalizeWakeTranscript(phrase);
    let recognition = null;
    let stopped = true;
    let restartTimer = 0;
    let wakeTimer = 0;
    let lastTranscript = '';

    const startRecognition = () => {
        if (stopped) return;
        recognition = new Recognition();
        recognition.lang = 'en-US';
        recognition.continuous = true;
        recognition.interimResults = true;
        recognition.processLocally = true;
        recognition.onresult = (event) => {
            lastTranscript = Array.from(event.results || [])
                .slice(event.resultIndex || 0)
                .map((result) => result?.[0]?.transcript || '')
                .join(' ');
            if (!normalizeWakeTranscript(lastTranscript).includes(normalizedPhrase)) return;
            window.clearTimeout(wakeTimer);
            wakeTimer = window.setTimeout(() => {
                onWake?.({
                    source: 'browser-speech-recognition',
                    transcript: lastTranscript,
                    tail: extractWakeTail(lastTranscript, phrase),
                    detectedAtMs: Date.now(),
                });
            }, WAKE_SETTLE_MS);
        };
        recognition.onend = () => {
            recognition = null;
            if (!stopped) restartTimer = window.setTimeout(startRecognition, 300);
        };
        recognition.onerror = (event) => {
            if (event?.error === 'not-allowed' || event?.error === 'service-not-allowed') {
                stopped = true;
                onError?.(event);
            }
        };
        recognition.start();
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
}

function normalizeWakeTranscript(value) {
    return String(value || '').toLowerCase().replace(/[^a-z0-9]+/g, ' ').replace(/\s+/g, ' ').trim();
}

function extractWakeTail(transcript, phrase) {
    const raw = String(transcript || '').trim();
    const normalizedPhrase = normalizeWakeTranscript(phrase);
    const words = raw.split(/\s+/);
    for (let index = 0; index < words.length; index += 1) {
        const candidate = normalizeWakeTranscript(words.slice(index).join(' '));
        if (!candidate.startsWith(normalizedPhrase)) continue;
        return words.slice(index + normalizedPhrase.split(' ').length).join(' ').trim();
    }
    return '';
}
