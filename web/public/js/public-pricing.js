(() => {
    const options = Array.from(document.querySelectorAll('.public-pricing input[data-billing-option]'));
    const labels = Array.from(document.querySelectorAll('.public-pricing [data-billing-label]'));
    const applyInterval = (interval) => {
        const normalized = interval === 'yearly' ? 'yearly' : 'monthly';
        options.forEach((option) => {
            option.checked = option.value === normalized;
        });
        labels.forEach((label) => {
            const active = label.dataset.billingLabel === normalized;
            label.classList.toggle('active', active);
            label.setAttribute('aria-pressed', active ? 'true' : 'false');
        });
    };
    options.forEach((option) => option.addEventListener('change', () => applyInterval(option.value)));
    applyInterval(new URLSearchParams(window.location.search).get('billing_interval'));
})();
