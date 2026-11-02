const assert = require('node:assert/strict');
const { test } = require('node:test');
const fs = require('node:fs/promises');
const { chromium } = require('playwright');

test('Ozon eligibility controls the real checkout pickup map', async (t) => {
    const base = process.env.LIVSI_TEST_BASE_URL;
    assert.ok(base);
    const browser = await chromium.launch({ executablePath: process.env.LIVSI_TEST_BROWSER_PATH });
    try {
        for (const width of [1440, 390]) {
            await t.test(`${width}px: denial, approval, phone change, retry and other carriers`, async () => {
                const context = await browser.newContext({ viewport: { width, height: 950 }, reducedMotion: 'reduce' });
                let csrf;
                try {
                    const catalog = await (await context.request.get(`${base}/catalog`)).text();
                    csrf = catalog.match(/name="_token"\s+value="([^"]+)"/)?.[1];
                    const variant = catalog.match(/name="variant_id"\s+value="(\d+)"/)?.[1];
                    assert.ok(csrf && variant);
                    await context.request.post(`${base}/cart/add`, { form: { _token: csrf, variant_id: variant, qty: '1' } });
                    await context.addInitScript(() => {
                        localStorage.setItem('livsi.delivery.city.v1', JSON.stringify({ city: 'Ставрополь', region: 'Ставропольский край' }));
                        window.ymaps = {
                            ready(callback) { callback(); }, geocode() { return Promise.reject(new Error('geocoder test')); },
                            SuggestView: class { constructor() { this.events = { add() {} }; } },
                            Placemark: class { constructor() { this.events = { add() {} }; } },
                            Clusterer: class { add() {} removeAll() {} },
                            Map: class { constructor() { this.geoObjects = { add() {} }; this.container = { fitToViewport() {} }; } setCenter() {} },
                        };
                    });
                    const page = await context.newPage();
                    const errors = [];
                    page.on('pageerror', error => errors.push(error.message));
                    let eligibility = false;
                    await page.route('**/delivery/ozon/check-client', route => eligibility === null
                        ? route.fulfill({ status: 503, json: {} }) : route.fulfill({ json: { can_be_delivered: eligibility } }));
                    await page.route('**/delivery/city/geocode', route => route.fulfill({ status: 429, json: {} }));
                    await page.route('**/delivery/points?*', route => {
                        const method = new URL(route.request().url()).searchParams.get('method');
                        const [id, provider] = { cdek_point: ['101', 'cdek'], ozon_point: ['102', 'ozondel'], yandex_point: ['103', 'yataxi'] }[method];
                        return route.fulfill({ json: { points: [{ id, provider, city: 'Ставрополь', region: 'Ставропольский край', address: 'Проверочный ПВЗ', lat: 45.03, lng: 41.97 }], has_more: false } });
                    });
                    await page.route('**/delivery/quote', route => route.fulfill({ json: { token: 'ozon-ui-quote', price: 30000, price_label: '300 ₽', days_min: 2, days_max: 3 } }));
                    await page.goto(`${base}/checkout`);
                    const invalidPhone = await context.request.post(`${base}/delivery/ozon/check-client`, {
                        headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf }, data: { phone: '+70000000000' },
                    });
                    assert.equal(invalidPhone.status(), 422);
                    assert.ok((await invalidPhone.json()).errors.phone);
                    if (await page.locator('[data-cookie-choice="necessary"]').isVisible()) await page.locator('[data-cookie-choice="necessary"]').click();
                    const ozonFilter = page.locator('[data-points-carrier][value="ozon_point"]');
                    const map = page.locator('[data-points-dialog]');
                    const openMap = async () => {
                        await page.locator('[data-points-search]').click();
                        await map.waitFor({ state: 'visible' });
                        await page.waitForFunction(() => document.querySelectorAll('.pickup-item').length >= 2);
                    };
                    await openMap();
                    assert.equal(await ozonFilter.isDisabled(), true);
                    assert.equal(await page.locator('.pickup-item').count(), 2);
                    await page.locator('[data-points-close]').click();
                    await page.locator('[name="phone"]').fill('+79000000000');
                    await page.waitForFunction(() => document.querySelector('[data-ozon-check-status]').textContent.includes('не подтвердил'));
                    await openMap();
                    assert.equal(await ozonFilter.isDisabled(), true);
                    assert.equal(await page.locator('.pickup-item').filter({ hasText: 'Ozon' }).count(), 0);
                    if (process.env.LIVSI_TEST_ARTIFACT_DIR) {
                        await fs.mkdir(process.env.LIVSI_TEST_ARTIFACT_DIR, { recursive: true });
                        await page.screenshot({ path: `${process.env.LIVSI_TEST_ARTIFACT_DIR}/ozon-denied-${width}.png` });
                    }
                    await page.locator('[data-points-close]').click();
                    eligibility = true;
                    await page.locator('[name="phone"]').fill('+79000000001');
                    await page.waitForFunction(() => !document.querySelector('[data-points-carrier][value="ozon_point"]').disabled);
                    await openMap();
                    assert.equal(await page.locator('.pickup-item').count(), 3, JSON.stringify(await page.evaluate(() => ({
                        carriers: [...document.querySelectorAll('[data-points-carrier]')].map(e => ({ value: e.value, checked: e.checked, disabled: e.disabled })),
                        query: document.querySelector('[data-points-filter]').value,
                        status: document.querySelector('[data-ozon-check-status]').textContent,
                        items: [...document.querySelectorAll('.pickup-item')].map(e => e.textContent),
                    }))));
                    await page.locator('.pickup-item').filter({ hasText: 'Ozon' }).click();
                    await page.waitForFunction(() => document.querySelector('[name="delivery_quote_token"]').value === 'ozon-ui-quote');
                    assert.equal(await page.locator('[data-point-id]').inputValue(), '102');
                    eligibility = null;
                    await page.locator('[name="phone"]').fill('+79000000002');
                    assert.equal(await page.locator('[data-point-id]').inputValue(), '');
                    assert.equal(await page.locator('[name="delivery_quote_token"]').inputValue(), '');
                    await page.waitForFunction(() => !document.querySelector('[data-ozon-check-retry]').hidden);
                    await openMap();
                    assert.match(await page.locator('dialog [data-ozon-check-status]').textContent(), /Не удалось проверить/);
                    eligibility = true;
                    await page.locator('[data-ozon-check-retry]').click();
                    await page.waitForFunction(() => !document.querySelector('[data-points-carrier][value="ozon_point"]').disabled);
                    await page.locator('.pickup-item').filter({ hasText: 'СДЭК' }).click();
                    await page.waitForFunction(() => document.querySelector('[name="delivery_quote_token"]').value === 'ozon-ui-quote');
                    eligibility = false;
                    await page.locator('[name="phone"]').fill('+79000000003');
                    await page.waitForFunction(() => document.querySelector('[data-ozon-check-status]').textContent.includes('не подтвердил'));
                    assert.equal(await page.locator('[data-point-id]').inputValue(), '101');
                    assert.equal(await page.locator('[name="delivery_quote_token"]').inputValue(), 'ozon-ui-quote');
                    assert.deepEqual(errors, []);
                } finally {
                    const cart = await (await context.request.get(`${base}/cart`)).text();
                    for (const item of new Set([...cart.matchAll(/action="([^"]*\/cart\/items\/\d+)"/g)].map(match => match[1]))) {
                        await context.request.post(item, { form: { _token: csrf, _method: 'DELETE' } });
                    }
                    await context.close();
                }
            });
        }
    } finally { await browser.close(); }
});
