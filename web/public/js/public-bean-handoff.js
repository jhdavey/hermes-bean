(function () {
    try {
        var raw = window.sessionStorage && window.sessionStorage.getItem('heybean.publicBean.handoff');
        if (!raw) return;
        var data = JSON.parse(raw);
        if (!data || !data.expiresAt || Date.now() > data.expiresAt) {
            window.sessionStorage.removeItem('heybean.publicBean.handoff');
            return;
        }
        var root = document.documentElement;
        if (Number.isFinite(Number(data.top))) root.style.setProperty('--public-bean-handoff-top', Number(data.top).toFixed(2) + 'px');
        if (Number.isFinite(Number(data.centerX))) root.style.setProperty('--public-bean-handoff-left', Number(data.centerX).toFixed(2) + 'px');
        root.dataset.publicBeanHandoff = 'true';
    } catch (_) {}
})();
