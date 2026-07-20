import { Conversation } from '@elevenlabs/client';

const tokenKey = 'heybean.web.token';
const root = document.getElementById('bean-elevenlabs-voice-poc');

if (root) {
    const startButton = root.querySelector('[data-poc-start]');
    const stopButton = root.querySelector('[data-poc-stop]');
    const statusEl = root.querySelector('[data-poc-status]');
    const detailEl = root.querySelector('[data-poc-detail]');
    const logEl = root.querySelector('[data-poc-log]');
    let conversation = null;

    const readToken = () => localStorage.getItem(tokenKey) || sessionStorage.getItem(tokenKey) || '';

    const setStatus = (status, detail = '') => {
        statusEl.textContent = status;
        if (detail) detailEl.textContent = detail;
    };

    const log = (event, payload = '') => {
        const stamp = new Date().toLocaleTimeString();
        const text = typeof payload === 'string' ? payload : JSON.stringify(payload, null, 2);
        logEl.value = `${logEl.value}[${stamp}] ${event}${text ? `: ${text}` : ''}\n`;
        logEl.scrollTop = logEl.scrollHeight;
    };

    const api = async (path, options = {}) => {
        const token = readToken();
        if (!token) {
            throw new Error('Sign in to Bean first, then reopen this POC page.');
        }
        const response = await fetch(`/api${path}`, {
            method: options.method || 'GET',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                Authorization: `Bearer ${token}`,
                ...(options.headers || {}),
            },
            body: options.body ? JSON.stringify(options.body) : undefined,
        });
        const payload = await response.json().catch(() => ({}));
        if (!response.ok) {
            throw new Error(payload.message || 'Request failed.');
        }
        return Object.prototype.hasOwnProperty.call(payload, 'data') ? payload.data : payload;
    };

    const updateButtons = (connected) => {
        startButton.disabled = connected;
        stopButton.disabled = !connected;
    };

    startButton?.addEventListener('click', async () => {
        try {
            setStatus('Requesting microphone…', 'Allow microphone access when the browser asks.');
            log('start_requested');
            await navigator.mediaDevices.getUserMedia({ audio: true });

            setStatus('Minting ElevenLabs token…', 'Asking Bean API for a short-lived ElevenLabs conversation token.');
            const token = await api('/bean/elevenlabs/conversation-token', { method: 'POST', body: {} });
            if (!token?.token) throw new Error('Bean did not return an ElevenLabs conversation token.');
            log('conversation_token_ready', { speech_engine_id: token.speech_engine_id });

            setStatus('Connecting…', 'Opening the ElevenLabs realtime conversation.');
            conversation = await Conversation.startSession({
                conversationToken: token.token,
                onConnect: () => {
                    updateButtons(true);
                    setStatus('Connected', 'Speak naturally. ElevenLabs is handling turn-taking and audio.');
                    log('connected');
                },
                onDisconnect: () => {
                    updateButtons(false);
                    setStatus('Disconnected', 'Conversation ended.');
                    log('disconnected');
                    conversation = null;
                },
                onError: (error) => {
                    setStatus('Error', error?.message || 'ElevenLabs conversation error.');
                    log('error', error?.message || String(error));
                },
                onMessage: (message) => log('message', message),
                onStatusChange: (status) => {
                    const value = typeof status === 'string' ? status : status?.status || JSON.stringify(status);
                    setStatus(value || 'Status changed');
                    log('status', status);
                },
                onModeChange: (mode) => log('mode', mode),
            });
        } catch (error) {
            updateButtons(false);
            setStatus('Could not start', error?.message || 'Unknown startup error.');
            log('start_error', error?.message || String(error));
        }
    });

    stopButton?.addEventListener('click', async () => {
        try {
            await conversation?.endSession?.();
        } finally {
            conversation = null;
            updateButtons(false);
            setStatus('Stopped', 'Conversation stopped.');
            log('stopped');
        }
    });
}
