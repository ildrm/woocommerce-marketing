/* First-party consent bridge. No browser storage, fingerprinting or third-party request. */
(function () {
    'use strict';
    const config = window.wmosTracking;
    if (!config) return;
    let bootstrap, purposes = [], events = [], timer;
    const request = (name, body) => fetch(config.endpoint + name, {
        method: body ? 'POST' : 'GET', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', ...(config.nonce ? { 'X-WP-Nonce': config.nonce } : {}) },
        ...(body ? { body: JSON.stringify(body), keepalive: true } : {})
    }).then(response => { if (!response.ok) throw new Error(config.messages.failed); return response.json(); });
    const flush = () => { if (!events.length || !purposes.includes('analytics')) return; const batch = events.splice(0, 20); request('events', { events: batch }).catch(() => {}); };
    const capture = (name, properties) => {
        if (!purposes.includes('analytics') || typeof crypto.randomUUID !== 'function') return;
        events.push({ id: crypto.randomUUID(), name, properties });
        if (events.length >= 20) flush(); else { clearTimeout(timer); timer = setTimeout(flush, 1500); }
    };
    const setPreferences = async selected => {
        bootstrap = await request('bootstrap');
        if (!bootstrap.enabled) throw new Error(config.messages.disabled);
        const result = await request('consent', { challenge: bootstrap.challenge, purposes: selected, policy_version: bootstrap.policy_version });
        purposes = result.purposes; if (!purposes.includes('analytics')) events = [];
        return result;
    };
    window.wmosConsent = { setPreferences, capture };
    document.addEventListener('click', async event => {
        if (!event.target.matches('[data-wmos-save]')) return;
        const form = event.target.closest('.wmos-preferences'), status = form.querySelector('[data-wmos-status]');
        event.target.disabled = true;
        try { await setPreferences(Array.from(form.querySelectorAll('[data-wmos-purpose]:checked'), input => input.dataset.wmosPurpose)); status.textContent = config.messages.saved; }
        catch (error) { status.textContent = error.message; }
        finally { event.target.disabled = false; }
    });
    request('bootstrap').then(result => {
        bootstrap = result; purposes = result.purposes || [];
        document.querySelectorAll('[data-wmos-purpose]').forEach(input => { input.checked = purposes.includes(input.dataset.wmosPurpose); });
        capture('page.viewed', { path: location.pathname });
        const product = document.querySelector('button[name="add-to-cart"]');
        if (product && /^\d+$/.test(product.value)) capture('product.viewed', { product_id: Number(product.value), path: location.pathname });
    }).catch(() => {});
    document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'hidden') flush(); });
}());
