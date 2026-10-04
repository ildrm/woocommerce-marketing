const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const jsQR = require('jsqr');
const fs = require('node:fs');
const path = require('node:path');
const errors = new WeakMap();
test.beforeEach(async ({ page }) => {
    errors.set(page, []); page.on('pageerror', error => errors.get(page).push(error.message));
    await page.route('**/*', route => { const address = new URL(route.request().url()); return ['http:', 'https:'].includes(address.protocol) && address.hostname !== '127.0.0.1' ? route.abort() : route.continue(); });
    await page.goto('/wp-login.php', { waitUntil: 'domcontentloaded' });
    await page.getByLabel('Username or Email Address').fill('wmos_test_admin');
    await page.getByLabel('Password', { exact: true }).fill(fs.readFileSync(path.join(__dirname, '../../.runtime/admin-password'), 'utf8').trim());
    await page.getByRole('button', { name: 'Log In', exact: true }).click();
    await page.waitForURL('**/wp-admin/**');
    await page.goto('/wp-admin/admin.php?page=woocommerce-marketing-os', { waitUntil: 'domcontentloaded' });
    await expect(page.locator('#wmos-admin-root h1')).toHaveText('Marketing operating system');
});
async function navigate(page, name) { await page.locator('.wmos-nav').getByRole('button', { name, exact: true }).click(); }
test('all enabled workspaces load actual server data and scoped assets do not leak to dashboard', async ({ page }) => {
    const labels = [['Campaigns', 'campaigns'], ['Automations', 'automations'], ['Segments', 'segments'], ['Promotions', 'promotions'], ['Loyalty and partner programs', 'programs'], ['Experiments', 'experiments'], ['Assets', 'assets'], ['Offline placements', 'offline'], ['Event marketing', 'marketing-events'], ['Influencers', 'influencers'], ['Content and SEO briefs', 'content'], ['Partners', 'partners'], ['Personalization', 'personalization'], ['Recommendations', 'recommendations'], ['Customers and consent', 'contacts'], ['Channels and integrations', 'providers'], ['Messages', 'messages'], ['Links and QR', 'links'], ['Analytics and attribution', 'reports'], ['System health', 'health'], ['Settings and import', 'settings']];
    for (const [label, route] of labels) { const response = page.waitForResponse(r => r.url().includes('/wmos/v1/' + route) && r.request().method() === 'GET'); await navigate(page, label); expect((await response).status(), route).toBe(200); console.log('Workspace data verified: ' + route); await expect(page.locator('.wmos-main h2').first()).toBeVisible(); await expect(page.locator('.wmos-main')).not.toContainText('Required marketing service unavailable'); }
    await expect(page.getByLabel('Consent policy version')).toBeVisible();
    const localized = await page.evaluate(() => ({
        locale: window.wmosAdmin.locale,
        timeZone: window.wmosAdmin.timeZone,
        amount: window.wmosBuilders.displayValue('net_revenue_minor', { net_revenue_minor: '9007199254740991', currency: 'USD', exponent: 2 }, 'en-US', 'UTC'),
        exact: window.wmosBuilders.displayValue('net_revenue_minor', { net_revenue_minor: '9007199254740993', currency: 'USD', exponent: 2 }, 'en-US', 'UTC'),
        date: window.wmosBuilders.displayValue('created_at', { created_at: '2026-01-01 23:00:00' }, 'en-GB', 'Asia/Tehran')
    }));
    expect(localized.locale).not.toContain('_'); expect(localized.timeZone).toBeTruthy();
    expect(localized.amount).toBe('$90,071,992,547,409.91'); expect(localized.exact).toBe('9007199254740993'); expect(localized.date).toBe('2 Jan 2026, 02:30');
    expect(errors.get(page)).toEqual([]);
    await page.goto('/wp-admin/index.php', { waitUntil: 'domcontentloaded' });
    expect(await page.locator('script[src*="/assets/admin.js"]').count()).toBe(0);
});
test('segment builder saves, publishes and previews; invalid JSON blocks saving', async ({ page }) => {
    await navigate(page, 'Segments'); await page.getByRole('button', { name: 'Create new', exact: true }).click();
    const name = 'Browser segment ' + Date.now(); await page.getByLabel('Name', { exact: true }).fill(name);
    await page.getByRole('button', { name: 'Save draft', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Edit draft', exact: true })).toBeVisible();
    await page.getByRole('button', { name: 'Publish version', exact: true }).click();
    await expect(page.locator('.wmos-uuid').first()).toContainText('Revision 2');
    await page.getByRole('button', { name: 'Preview matching contacts', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Operation result', exact: true })).toBeVisible();
    await page.getByLabel('Operator', { exact: true }).selectOption('in');
    await page.getByLabel('Value', { exact: true }).fill('[1,');
    await expect(page.locator('[data-wmos-invalid="true"]')).toBeVisible();
    await page.getByRole('button', { name: 'Save draft', exact: true }).click();
    await expect(page.locator('.components-notice')).toContainText('Complete or correct');
    await page.getByLabel('Value', { exact: true }).fill('[1, 2]');
    await expect(page.locator('[data-wmos-invalid="true"]')).toHaveCount(0);
    await page.getByRole('button', { name: 'Save draft', exact: true }).click();
    await expect(page.locator('.wmos-uuid').first()).toContainText('Revision 3');
    expect(errors.get(page)).toEqual([]);
});
test('workflow outline supports keyboard editing and preserves a valid connected draft', async ({ page }) => {
    await navigate(page, 'Automations'); await page.getByRole('button', { name: 'Create new', exact: true }).click();
    await page.getByLabel('Name', { exact: true }).fill('Browser workflow ' + Date.now());
    const identifier = page.getByLabel('Node identifier', { exact: true }).first(); await identifier.focus(); await identifier.fill('entrance');
    await expect(page.getByLabel('From node', { exact: true }).first()).toHaveValue('entrance');
    await page.getByRole('link', { name: 'entrance · trigger', exact: true }).focus(); await page.keyboard.press('Enter');
    await expect(page.locator('#wmos-node-0')).toBeInViewport();
    await page.getByRole('button', { name: 'Save draft', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Edit draft', exact: true })).toBeVisible();
    await page.getByRole('button', { name: 'Publish version', exact: true }).click();
    await expect(page.locator('.wmos-uuid').first()).toContainText('Revision 2');
    expect(errors.get(page)).toEqual([]);
});
test('contact choices and write-only channel connection round trip without credential disclosure', async ({ page }) => {
    await navigate(page, 'Customers and consent'); const email = 'browser-' + Date.now() + '@example.test';
    await page.getByLabel('Email for new profile').fill(email); await page.getByRole('button', { name: 'Create contact', exact: true }).click();
    await expect(page.getByRole('heading', { name: email, exact: true })).toBeVisible();
    await page.getByLabel('Policy version', { exact: true }).fill('browser-policy-v1'); await page.getByLabel('Evidence or request reference').fill('Verified fixture request');
    await page.getByRole('button', { name: 'Record grant', exact: true }).click(); await expect(page.locator('.components-notice')).toContainText('Operation completed');
    await page.getByRole('button', { name: 'View consent history', exact: true }).click(); await expect(page.locator('.wmos-main pre').last()).toContainText('browser-policy-v1');
    await page.getByLabel('Identity value', { exact: true }).fill(email); await page.getByLabel('I verified this destination using the referenced evidence').check();
    await page.getByRole('button', { name: 'Record identity', exact: true }).click(); await expect(page.locator('.components-notice')).toContainText('Operation completed');
    await navigate(page, 'Channels and integrations'); const name = 'Browser disabled connection ' + Date.now(); const credential = 'fixture-only-never-sent-' + Date.now();
    await page.getByLabel('Connection name').fill(name); await page.getByLabel('Credential', { exact: true }).fill(credential);
    await page.getByLabel('Safe provider configuration').fill(JSON.stringify({ from: 'test@example.test', enabled: false, policy_acknowledged: false }));
    await page.getByRole('button', { name: 'Save connection', exact: true }).click(); await expect(page.locator('.components-notice')).toContainText('Connection saved');
    await expect(page.getByLabel('Credential', { exact: true })).toHaveValue(''); expect(await page.locator('#wmos-admin-root').textContent()).not.toContain(credential);
    expect(errors.get(page)).toEqual([]);
});
test('tracking QR contains the exact local tracking URL and exports a quiet-zone SVG', async ({ page }) => {
    await navigate(page, 'Links and QR'); await page.getByLabel('Store destination URL').fill('http://127.0.0.1:8089/wmos-browser-' + Date.now());
    await page.getByRole('button', { name: 'Create tracking link', exact: true }).click();
    await expect(page.locator('.components-notice').filter({ hasText: 'error' })).toHaveCount(0);
    const row = page.locator('.wmos-created-link'); await expect(row.getByRole('button', { name: 'Show QR', exact: true })).toBeVisible(); await row.getByRole('button', { name: 'Show QR', exact: true }).click();
    const pixels = await row.locator('img').evaluate(async image => {
        await image.decode(); const canvas = document.createElement('canvas'); canvas.width = image.naturalWidth; canvas.height = image.naturalHeight;
        const ctx = canvas.getContext('2d'); ctx.drawImage(image, 0, 0); return { width: canvas.width, height: canvas.height, data: Array.from(ctx.getImageData(0, 0, canvas.width, canvas.height).data) };
    });
    const decoded = jsQR(Uint8ClampedArray.from(pixels.data), pixels.width, pixels.height); expect(decoded).not.toBeNull(); expect(decoded.data).toBe(await row.getByRole('link').getAttribute('href'));
    const downloadPromise = page.waitForEvent('download'); await row.getByRole('button', { name: 'Download QR SVG', exact: true }).click(); const download = await downloadPromise;
    expect(download.suggestedFilename()).toMatch(/^placement-.*\.svg$/); const file = await download.path(); const svg = fs.readFileSync(file, 'utf8'); expect(svg).toContain('<svg'); expect(svg).toContain('viewBox');
    expect(errors.get(page)).toEqual([]);
});
test('scoped native admin has no serious accessibility violations and survives RTL narrow viewport', async ({ page }) => {
    await navigate(page, 'Automations'); await page.getByRole('button', { name: 'Create new', exact: true }).click();
    const results = await new AxeBuilder({ page }).include('#wmos-admin-root').withTags(['wcag2a', 'wcag2aa', 'wcag21aa']).analyze();
    expect(results.violations.filter(v => ['critical', 'serious'].includes(v.impact)).map(v => ({ id: v.id, nodes: v.nodes.map(n => n.target) }))).toEqual([]);
    await page.setViewportSize({ width: 768, height: 1024 }); await page.evaluate(() => { document.documentElement.dir = 'rtl'; });
    await expect(page.getByRole('button', { name: 'Save draft', exact: true })).toBeVisible();
    expect(await page.locator('#wmos-admin-root').evaluate(el => el.scrollWidth <= el.clientWidth + 2)).toBe(true);
});
