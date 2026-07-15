import { normalizeBeanVoiceProjection } from '/resources/js/heybean/beanVoiceProjectionStream.js';
import { beanVoiceResponseAuthorizationFailure } from '/resources/js/heybean/beanVoiceRealtimeTransport.js';

const chat = document.querySelector('#chat');
const dock = document.querySelector('#dock');
const result = document.querySelector('#result');

window.beanVoiceHarness = {
    project(payload) {
        const projection = normalizeBeanVoiceProjection(payload);
        dock.replaceChildren(...projection.jobs.map((job) => {
            const item = document.createElement('li');
            item.textContent = job.label;
            item.dataset.status = job.status;
            return item;
        }));
        result.textContent = JSON.stringify(projection);
        return projection;
    },
    authorize(input) {
        return beanVoiceResponseAuthorizationFailure(input);
    },
    snapshot() {
        return {
            chat: chat.textContent,
            dock: dock.textContent,
            result: result.textContent,
        };
    },
};
window.voiceHarnessReady = Promise.resolve(true);
