<style>
    .public-pricing {
        scroll-margin-top: 48px;
    }
    .public-pricing .segmented {
        overflow: visible;
    }
    .public-pricing .segmented input {
        position: absolute;
        width: 1px;
        height: 1px;
        opacity: 0;
        pointer-events: none;
    }
    .public-pricing .segmented .billing-option {
        position: relative;
        z-index: 2;
        height: 34px;
        display: grid;
        place-items: center;
        border: 0;
        border-radius: 999px;
        background: transparent;
        color: var(--pb-muted);
        font: inherit;
        font-size: 14px;
        font-weight: 700;
        cursor: pointer;
        transition: color .18s ease;
    }
    .public-pricing .segmented input:checked + .billing-option,
    .public-pricing .segmented .billing-option.active {
        color: var(--pb-green-ink);
    }
    .public-pricing .segmented input:focus-visible + .billing-option {
        outline: 2px solid var(--pb-green-dark);
        outline-offset: 2px;
    }
    .public-pricing .billing-toggle-thumb {
        position: absolute;
        z-index: 1;
        top: 4px;
        left: 4px;
        width: calc((100% - 8px) / 2);
        height: 34px;
        display: block;
        border-radius: 999px;
        background: var(--pb-green);
        box-shadow: 0 1px 2px rgba(16, 24, 40, .08);
        transition: transform .24s cubic-bezier(.2, .8, .2, 1);
    }
    .public-pricing .segmented #billing-yearly:checked ~ .billing-toggle-thumb {
        transform: translateX(100%);
    }
    .public-pricing .yearly-price,
    .public-pricing .yearly-period,
    .public-pricing .yearly-link {
        display: none;
    }
    body:has(.public-pricing #billing-yearly:checked) .public-pricing .monthly-price,
    body:has(.public-pricing #billing-yearly:checked) .public-pricing .monthly-period,
    body:has(.public-pricing #billing-yearly:checked) .public-pricing .monthly-link {
        display: none;
    }
    body:has(.public-pricing #billing-yearly:checked) .public-pricing .yearly-price,
    body:has(.public-pricing #billing-yearly:checked) .public-pricing .yearly-period,
    body:has(.public-pricing #billing-yearly:checked) .public-pricing .yearly-link {
        display: inline-flex;
    }
    .public-pricing .price .yearly-price,
    .public-pricing .price .yearly-period {
        display: none;
    }
    body:has(.public-pricing #billing-yearly:checked) .public-pricing .price .yearly-price,
    body:has(.public-pricing #billing-yearly:checked) .public-pricing .price .yearly-period {
        display: inline;
    }
    .public-pricing .plan-actions {
        width: 100%;
        display: grid;
        margin-top: auto;
    }
    .public-pricing .plan .plan-actions .button {
        margin-top: 0;
    }
    .public-pricing .section-head p {
        margin: 16px auto 0;
        color: var(--pb-green-dark);
        font-size: 18px;
        font-weight: 800;
    }
</style>
