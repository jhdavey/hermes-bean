import { Conversation } from '@elevenlabs/client';
import '../css/public-bean.css';

const WAKE_PHRASE = 'Hey Bean';
const IDLE_CLOSE_MS = 30000;
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
    let pendingNavigation = null;

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
            stopVoiceConversation().finally(() => restartWakeListening());
        }, IDLE_CLOSE_MS);
    };

    const stopWakeListening = () => {
        wakeDetector?.stop?.();
        wakeDetector = null;
    };

    const stopVoiceConversation = async () => {
        stopIdleTimer();
        voiceActive = false;
        lastVoiceMode = '';
        pendingNavigation = null;
        const activeConversation = conversation;
        conversation = null;
        await activeConversation?.endSession?.().catch(() => {});
    };

    const disable = async () => {
        lifecycleRevision += 1;
        enabled = false;
        starting = false;
        setStatus('disabled', 'Tap to enable');
        stopWakeListening();
        await stopVoiceConversation();
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
        setStatus('connecting', 'Hey Bean heard…');
        try {
            await startVoiceConversation(tail, revision);
        } catch (error) {
            if (!isCurrentLifecycle(revision)) return;
            await stopVoiceConversation();
            setStatus('error', error?.status === 429 ? 'Demo limit reached' : 'Bean could not connect');
            window.setTimeout(() => restartWakeListening(), 1500);
        }
    }

    async function startVoiceConversation(wakeTail, revision) {
        const turnstileToken = await getTurnstileToken(root);
        const session = await postJson(root.dataset.conversationTokenUrl, {
            client_timezone: Intl.DateTimeFormat().resolvedOptions().timeZone || null,
            page_path: window.location.pathname,
            turnstile_token: turnstileToken,
        });
        if (!isCurrentLifecycle(revision)) return;
        if (!session?.token) throw new Error('Bean voice did not return a token.');

        const nextConversation = await Conversation.startSession({
            conversationToken: session.token,
            connectionType: 'webrtc',
            textOnly: false,
            useWakeLock: true,
            userId: session.landing_session_id ? `bean-visitor-${session.landing_session_id}` : undefined,
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
                        pendingNavigation = showLandingUiAction(response?.ui_action);
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
                pendingNavigation = null;
                lastActivityAt = Date.now();
                setStatus('listening', 'Listening…');
            },
            onDisconnect: () => {
                if (!isCurrentLifecycle(revision)) return;
                voiceActive = false;
                conversation = null;
                lastVoiceMode = '';
                pendingNavigation = null;
                stopIdleTimer();
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
                const previousMode = lastVoiceMode;
                lastVoiceMode = nextMode;
                lastActivityAt = Date.now();
                if (nextMode === 'speaking') {
                    stopIdleTimer();
                    setStatus('speaking', 'Speaking…');
                } else if (nextMode === 'listening') {
                    setStatus('listening', 'Listening…');
                    scheduleIdleClose();
                    if (previousMode === 'speaking' && pendingNavigation) {
                        const navigation = pendingNavigation;
                        pendingNavigation = null;
                        stopIdleTimer();
                        setStatus('navigating', `Opening ${navigation.label}…`);
                        window.setTimeout(() => {
                            if (isCurrentLifecycle(revision)) window.location.assign(navigation.href);
                        }, 250);
                    }
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
        setStatus('thinking', 'Thinking…');
        conversation.sendUserMessage?.(wakeTail || WAKE_PHRASE);
        conversation.sendUserActivity?.();
    }

    button.addEventListener('click', () => {
        if (enabled) disable();
        else enable();
    });

    window.addEventListener('pagehide', () => {
        stopWakeListening();
        stopVoiceConversation();
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
        pricing: { selector: '#plans', href: '/pricing#plans', label: 'pricing' },
    };
    const target = targets[String(action || '').toLowerCase()];
    if (!target) return null;

    const section = document.querySelector(target.selector);
    if (!section) return { href: target.href, label: target.label };

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
                });
            }, 450);
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
