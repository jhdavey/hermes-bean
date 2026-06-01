const wakeStarter = '(?:hey|hay|hi|hello|okay|ok|kay)';
const beanVariant =
    '(?:bean|beans|been|ben|beam|beem|bein|being|bin|bing|bien|bain|bane|dean|deen)';
const compactBeanVariant = 'b(?:ean|eans|een|en|eam|eem|ein|eing|in|ing|ien|ain|ane)';

const wakePhrasePattern = new RegExp(
    [
        `(?:^|\\s)${wakeStarter}\\s*,?\\s*${beanVariant}\\b[\\s,.:;!?-]*`,
        `(?:^|\\s)${wakeStarter}\\s*${compactBeanVariant}\\b[\\s,.:;!?-]*`,
        `(?:^|\\s)${wakeStarter}\\s*,?\\s*(?:b|bee)\\b[\\s,.:;!?-]*`,
        '^\\s*a\\s+bean\\b[\\s,.:;!?-]*',
    ].join('|'),
    'i',
);

export function commandAfterWakePhrase(transcript) {
    const text = String(transcript || '').replace(/\s+/g, ' ').trim();
    if (!text) return null;
    const match = text.match(wakePhrasePattern);
    if (!match) return null;
    return text.slice(match.index + match[0].length).replace(/\s+/g, ' ').trim();
}
