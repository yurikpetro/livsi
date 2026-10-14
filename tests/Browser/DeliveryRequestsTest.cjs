const assert = require('node:assert/strict');
const { test } = require('node:test');
const { chromium } = require('playwright');

async function withCheckout(browser, run) {
    const baseUrl = process.env.LIVSI_TEST_BASE_URL;
    const context = await browser.newContext({ reducedMotion: 'reduce' });
    let csrf;
    try {
        const catalog = await (await context.request.get(`${baseUrl}/catalog`)).text();
        csrf = catalog.match(/name="_token"\s+value="([^"]+)"/)?.[1];
        const variant = catalog.match(/name="variant_id"\s+value="(\d+)"/)?.[1];
        assert.ok(csrf && variant);
        await context.request.post(`${baseUrl}/cart/add`, { form: { _token: csrf, variant_id: variant, qty: '1' } });
        await context.addInitScript(() => {
            localStorage.setItem('livsi.delivery.city.v1', JSON.stringify({ city: 'Ставрополь', region: 'Ставропольский край' }));
            window.deliveryRequestTest = { pending: [] };
            window.ymaps = {
                ready(callback) { callback(); },
                geocode() { return Promise.reject(new Error('Test: JS geocoder unavailable')); },
                SuggestView: class { constructor() { this.events = { add() {} }; } },
                Placemark: class { constructor() { this.events = { add() {} }; } },
                Clusterer: class { add() {} removeAll() {} },
                Map: class {
                    constructor() { this.geoObjects = { add() {} }; this.container = { fitToViewport() {} }; }
                    setCenter() {}
                },
            };
        });
        const page = await context.newPage();
        const errors = [];
        page.on('pageerror', (error) => errors.push(error.message));
        if (process.env.LIVSI_TEST_LOCAL_ASSETS === '1') {
            await page.route('**/build/assets/app-*.js', (route) => route.fulfill({ path: 'resources/js/app.js', contentType: 'text/javascript' }));
        }
        await run(page, baseUrl);
        assert.deepEqual(errors, []);
    } finally {
        const cart = await (await context.request.get(`${baseUrl}/cart`)).text();
        for (const item of new Set([...cart.matchAll(/action="([^"]*\/cart\/items\/\d+)"/g)].map((match) => match[1]))) {
            await context.request.post(item, { form: { _token: csrf, _method: 'DELETE' } });
        }
        await context.close();
    }
}

function pointResponse(route) {
    const url = new URL(route.request().url());
    const carriers = { cdek_point: ['101', 'cdek'], ozon_point: ['102', 'ozonlog'], yandex_point: ['103', 'yataxi'] };
    const [id, provider] = carriers[url.searchParams.get('method')];
    return { points: [{ id, provider, city: url.searchParams.get('city'), region: url.searchParams.get('region'),
        address: 'Проверочный ПВЗ', lat: 45.03, lng: 41.97 }], has_more: false };
}

test('Delivery request deduplication and rate-limit fallback', async (t) => {
    assert.ok(process.env.LIVSI_TEST_BASE_URL, 'Set LIVSI_TEST_BASE_URL');
    const browser = await chromium.launch({ executablePath: process.env.LIVSI_TEST_BROWSER_PATH });
    try {
        await t.test('points render before geocoding, repeated events and reopening do not refetch', async () => {
            await withCheckout(browser, async (page, baseUrl) => {
                let pointRequests = 0;
                let geocodeRequests = 0;
                let releaseGeocode;
                const gate = new Promise((resolve) => { releaseGeocode = resolve; });
                await page.route('**/delivery/points?*', (route) => {
                    pointRequests++;
                    return route.fulfill({ json: pointResponse(route) });
                });
                await page.route('**/delivery/city/geocode', async (route) => {
                    geocodeRequests++;
                    await gate;
                    return route.fulfill({ status: 429, headers: { 'Retry-After': '60' }, json: { message: 'Too many requests' } });
                });
                try {
                    await page.goto(`${baseUrl}/checkout`);
                    const geocodeRequest = page.waitForRequest((request) => request.url().endsWith('/delivery/city/geocode'));
                    await page.locator('[name="delivery_method"][value="pickup_point"]').check();
                    await page.waitForFunction(() => document.querySelectorAll('.pickup-item').length === 3);
                    await geocodeRequest;
                    await page.evaluate(() => {
                        for (let i = 0; i < 20; i++) document.querySelector('[name="delivery_city"]').dispatchEvent(new Event('change', { bubbles: true }));
                    });
                    const response = page.waitForResponse((item) => item.url().endsWith('/delivery/city/geocode'));
                    releaseGeocode();
                    assert.equal((await response).status(), 429);
                    for (let i = 0; i < 5; i++) {
                        await page.locator('[data-points-close]').click();
                        await page.locator('[data-points-search]').click();
                    }
                    assert.equal(pointRequests, 3);
                    assert.equal(geocodeRequests, 1);
                    assert.equal(await page.locator('.pickup-item').count(), 3);
                    await page.locator('.pickup-item').first().click();
                    assert.ok(await page.locator('[data-point-id]').inputValue());
                    assert.equal(await page.locator('[data-points-dialog]').evaluate((dialog) => dialog.open), false);
                } finally { releaseGeocode(); }
            });
        });

        await t.test('429 stops the loading state and reopening respects Retry-After', async () => {
            await withCheckout(browser, async (page, baseUrl) => {
                let pointRequests = 0;
                await page.route('**/delivery/points?*', (route) => {
                    pointRequests++;
                    return route.fulfill({ status: 429, headers: { 'Retry-After': '60' }, json: { message: 'Too many requests' } });
                });
                await page.route('**/delivery/city/geocode', (route) => route.fulfill({ status: 429, json: {} }));
                await page.goto(`${baseUrl}/checkout`);
                await page.locator('[name="delivery_method"][value="pickup_point"]').check();
                await page.waitForFunction(() => document.querySelector('[data-points-status]').textContent.includes('Слишком много запросов'));
                assert.doesNotMatch(await page.locator('[data-points-list]').textContent(), /загружаем/i);
                for (let i = 0; i < 5; i++) {
                    await page.locator('[data-points-close]').click();
                    await page.locator('[data-points-search]').click();
                }
                assert.equal(pointRequests, 3);
                assert.match(await page.locator('[data-points-status]').textContent(), /через \d+ сек/);
                assert.equal(await page.locator('[data-point-id]').inputValue(), '');
            });
        });

        await t.test('changing city cancels the obsolete server geocoder fallback', async () => {
            await withCheckout(browser, async (page, baseUrl) => {
                const geocodedCities = [];
                await page.route('**/delivery/points?*', (route) => route.fulfill({ json: pointResponse(route) }));
                await page.route('**/delivery/city/geocode', (route) => {
                    geocodedCities.push(route.request().postDataJSON().city);
                    return route.fulfill({ status: 429, json: {} });
                });
                await page.goto(`${baseUrl}/checkout`);
                await page.evaluate(() => {
                    ymaps.geocode = () => new Promise((resolve, reject) => deliveryRequestTest.pending.push(reject));
                });
                await page.locator('[name="delivery_method"][value="pickup_point"]').check();
                await page.waitForFunction(() => deliveryRequestTest.pending.length === 1 && document.querySelectorAll('.pickup-item').length === 3);
                await page.locator('[data-points-close]').click();
                await page.locator('[data-city-open]').click();
                await page.locator('[data-popular-city="Краснодар"]').click();
                assert.equal(await page.locator('[name="delivery_city"]').inputValue(), 'Краснодар');
                assert.equal(await page.locator('[name="delivery_region"]').inputValue(), 'Краснодарский край');
                assert.equal(await page.locator('[data-city-label]').textContent(), 'Краснодар');
                await page.waitForFunction(() => deliveryRequestTest.pending.length === 2 && document.querySelectorAll('.pickup-item').length === 3);
                const response = page.waitForResponse((item) => item.url().endsWith('/delivery/city/geocode'));
                await page.evaluate(() => deliveryRequestTest.pending.forEach((reject) => reject(new Error('Test: unavailable'))));
                await response;
                assert.deepEqual(geocodedCities, ['Краснодар']);
            });
        });
    } finally { await browser.close(); }
});
