const assert = require('node:assert/strict');
const fs = require('node:fs/promises');
const { test } = require('node:test');
const { chromium } = require('playwright');

async function withCheckout(browser, run, width = 1440) {
    const baseUrl = process.env.LIVSI_TEST_BASE_URL;
    const context = await browser.newContext({ reducedMotion: 'reduce', viewport: { width, height: 900 } });
    let csrf;
    try {
        const catalog = await (await context.request.get(`${baseUrl}/catalog`)).text();
        csrf = catalog.match(/name="_token"\s+value="([^"]+)"/)?.[1];
        const variant = catalog.match(/name="variant_id"\s+value="(\d+)"/)?.[1];
        assert.ok(csrf && variant);
        await context.request.post(`${baseUrl}/cart/add`, { form: { _token: csrf, variant_id: variant, qty: '1' } });
        await context.addInitScript(() => {
            document.addEventListener('DOMContentLoaded', () => {
                const phone = document.querySelector('[name="phone"]');
                if (phone) {
                    phone.value = '+79000000000';
                    phone.dispatchEvent(new Event('input', { bubbles: true }));
                }
            });
            localStorage.setItem('livsi.delivery.city.v1', JSON.stringify({ city: 'Ставрополь', region: 'Ставропольский край' }));
            window.deliveryRequestTest = { pending: [] };
            window.ymaps = {
                ready(callback) { callback(); },
                geocode() { return Promise.reject(new Error('Test: JS geocoder unavailable')); },
                SuggestView: class { constructor() { this.events = { add() {} }; } },
                Placemark: class { constructor(coordinates, properties) { this.properties = properties; this.events = { add() {} }; } },
                Clusterer: class {
                    constructor() { window.deliveryRequestTest.clusterer = this; this.points = []; }
                    add(points) { this.points.push(...points); }
                    removeAll() { this.points = []; }
                },
                Map: class {
                    constructor() { this.geoObjects = { add() {} }; this.container = { fitToViewport() {} }; }
                    setCenter() {}
                },
            };
        });
        const page = await context.newPage();
        const errors = [];
        page.on('pageerror', (error) => errors.push(error.message));
        await page.route('**/delivery/ozon/check-client', route => route.fulfill({ json: { can_be_delivered: true } }));
        if (process.env.LIVSI_TEST_LOCAL_ASSETS === '1') {
            await page.route('**/build/assets/delivery-quote.js', (route) => route.fulfill({ path: 'resources/js/delivery-quote.js', contentType: 'text/javascript' }));
            await page.route('**/build/assets/ozon-client-check.js', (route) => route.fulfill({ path: 'resources/js/ozon-client-check.js', contentType: 'text/javascript' }));
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
        for (const width of [1440, 390]) {
            await t.test(`${width}px: carrier checkboxes filter list and map together with address search`, async () => {
                await withCheckout(browser, async (page, baseUrl) => {
                    let requests = 0;
                    await page.route('**/delivery/points?*', (route) => {
                        requests++;
                        const response = pointResponse(route);
                        if (new URL(route.request().url()).searchParams.get('method') === 'cdek_point') {
                            response.points = Array.from({ length: 82 }, (_, index) => ({
                                ...response.points[0], id: `cdek-${index}`, address: `Ставрополь, Ленина, ${index + 1}`,
                            }));
                        }
                        return route.fulfill({ json: response });
                    });
                    await page.route('**/delivery/city/geocode', (route) => route.fulfill({ status: 429, json: {} }));
                    await page.goto(`${baseUrl}/checkout`);
                    if (await page.locator('[data-cookie-choice="necessary"]').isVisible()) {
                        await page.locator('[data-cookie-choice="necessary"]').click();
                    }
                    await page.locator('[data-points-search]').check();
                    await page.waitForFunction(() => deliveryRequestTest.clusterer?.points.length === 84);
                    assert.equal(await page.locator('[data-points-carrier]:checked').count(), 3);
                    assert.equal(await page.locator('.pickup-item').count(), 80);
                    await page.locator('[data-points-more]').click();
                    assert.equal(await page.locator('.pickup-item').count(), 84);

                    const carriers = page.locator('[data-points-carrier]');
                    for (const method of ['ozon_point', 'cdek_point', 'yandex_point']) {
                        for (const checkbox of await carriers.all()) {
                            await checkbox.setChecked(await checkbox.getAttribute('value') === method);
                        }
                        const expected = method === 'cdek_point' ? 82 : 1;
                        await page.waitForFunction((size) => deliveryRequestTest.clusterer.points.length === size, expected);
                        const label = { ozon_point: 'Ozon', cdek_point: 'СДЭК', yandex_point: 'Яндекс' }[method];
                        assert.deepEqual([...new Set(await page.locator('.pickup-item-provider').allTextContents())], [label]);
                        assert.equal(await page.locator('.pickup-item').count(), Math.min(expected, 80));
                        assert.match(await page.locator('[data-points-count]').textContent(), new RegExp(`^${expected} ПВЗ`));
                    }
                    await page.locator('[data-points-carrier][value="ozon_point"]').check();
                    await page.waitForFunction(() => deliveryRequestTest.clusterer.points.length === 2);
                    assert.equal(await page.locator('.pickup-item').count(), 2);
                    await page.locator('[data-points-filter]').fill('Ленина');
                    await page.waitForFunction(() => deliveryRequestTest.clusterer.points.length === 0);
                    assert.equal(await page.locator('.pickup-item').count(), 0);
                    assert.match(await page.locator('[data-points-list]').textContent(), /Измените поиск/);
                    await page.locator('[data-points-carrier][value="cdek_point"]').check();
                    await page.waitForFunction(() => deliveryRequestTest.clusterer.points.length === 82);
                    assert.equal(await page.locator('.pickup-item').count(), 80);
                    await page.locator('[data-points-filter]').fill('Ленина, 82');
                    await page.waitForFunction(() => deliveryRequestTest.clusterer.points.length === 1);
                    assert.equal(await page.locator('.pickup-item-address').textContent(), 'Ставрополь, Ленина, 82');
                    assert.equal(await page.locator('[data-points-more]').isVisible(), false);
                    await page.locator('[data-points-filter]').fill('');
                    for (const checkbox of await carriers.all()) await checkbox.uncheck();
                    await page.waitForFunction(() => deliveryRequestTest.clusterer.points.length === 0);
                    assert.equal(await page.locator('.pickup-item').count(), 0);
                    assert.match(await page.locator('[data-points-list]').textContent(), /Выберите хотя бы одну службу/);
                    await page.locator('[data-points-carrier][value="ozon_point"]').focus();
                    await page.keyboard.press('Space');
                    await page.waitForFunction(() => deliveryRequestTest.clusterer.points.length === 1);
                    if (process.env.LIVSI_TEST_ARTIFACT_DIR) {
                        await fs.mkdir(process.env.LIVSI_TEST_ARTIFACT_DIR, { recursive: true });
                        await page.screenshot({ path: `${process.env.LIVSI_TEST_ARTIFACT_DIR}/pickup-filters-${width}.png` });
                    }
                    await page.locator('.pickup-item').click();
                    assert.equal(await page.locator('[data-point-method]').inputValue(), 'ozon_point');
                    assert.equal(await page.locator('[data-point-id]').inputValue(), '102');
                    await page.locator('[data-points-search]').click();
                    await page.waitForFunction(() => document.querySelector('[data-points-dialog]').open);
                    assert.equal(await page.locator('[data-points-carrier]:checked').count(), 1);
                    assert.equal(await page.locator('.pickup-item.is-selected').count(), 1);
                    assert.equal(requests, 3);
                }, width);
            });
            await t.test(`${width}px: one pickup card selects, reopens and preserves the chosen point`, async () => {
                await withCheckout(browser, async (page, baseUrl) => {
                    let quotes = 0;
                    await page.route('**/delivery/points?*', (route) => {
                        const response = pointResponse(route);
                        response.points[0].address = 'Проверочный ПВЗ · Ставропольский край, село Александровское, улица Карла Маркса, дом 35';
                        return route.fulfill({ json: response });
                    });
                    await page.route('**/delivery/city/geocode', (route) => route.fulfill({ status: 429, json: {} }));
                    await page.route('**/delivery/quote', (route) => {
                        quotes++;
                        return route.fulfill({ json: { token: 'card-quote', price: 30000, price_label: '300 ₽', days_min: 2, days_max: 3 } });
                    });
                    await page.goto(`${baseUrl}/checkout`);
                    if (await page.locator('[data-cookie-choice="necessary"]').isVisible()) {
                        await page.locator('[data-cookie-choice="necessary"]').click();
                    }
                    assert.equal(await page.locator('button[data-points-search]').count(), 0);
                    assert.equal(await page.locator('[data-points-search]').count(), 1);
                    await page.locator('[data-points-search]').focus();
                    await page.keyboard.press('Enter');
                    await page.waitForFunction(() => document.querySelector('[data-points-dialog]').open && document.querySelectorAll('.pickup-item').length === 3);
                    await page.locator('.pickup-item').first().click();
                    const chosen = await page.locator('[data-point-id]').inputValue();
                    assert.ok(chosen);
                    assert.match(await page.locator('[data-pickup-choice]').textContent(), /Проверочный ПВЗ/);
                    assert.match(await page.locator('[data-pickup-action]').textContent(), /Изменить/);
                    if (await page.locator('[data-delivery-quote]').count()) {
                        await page.waitForFunction(() => document.querySelector('[name="delivery_quote_token"]').value === 'card-quote');
                        assert.equal(quotes, 1);
                        assert.equal(await page.locator('[data-checkout-delivery-price]').textContent(), '300 ₽');
                        const itemsTotal = Number(await page.locator('[data-checkout-total]').getAttribute('data-items-total'));
                        const expectedTotal = new Intl.NumberFormat('ru-RU', { style: 'currency', currency: 'RUB' }).format((itemsTotal + 30000) / 100);
                        assert.equal(await page.locator('[data-checkout-total]').textContent(), expectedTotal);
                        assert.equal(await page.locator('[data-delivery-recalculate]').isVisible(), false);
                    }
                    const cardBounds = await page.locator('[data-pickup-choice]').boundingBox();
                    assert.ok(cardBounds.x >= 0 && cardBounds.x + cardBounds.width <= width);
                    if (process.env.LIVSI_TEST_ARTIFACT_DIR) {
                        await fs.mkdir(process.env.LIVSI_TEST_ARTIFACT_DIR, { recursive: true });
                        await page.locator('[data-pickup-choice]').evaluate((card) => card.scrollIntoView({ block: 'center', behavior: 'instant' }));
                        await page.screenshot({ path: `${process.env.LIVSI_TEST_ARTIFACT_DIR}/pickup-card-${width}.png` });
                        await page.locator('[data-checkout-total]').evaluate((total) => total.scrollIntoView({ block: 'center', behavior: 'instant' }));
                        await page.screenshot({ path: `${process.env.LIVSI_TEST_ARTIFACT_DIR}/delivery-summary-${width}.png` });
                    }
                    await page.locator('[data-pickup-choice]').click();
                    await page.waitForFunction(() => document.querySelector('[data-points-dialog]').open);
                    assert.equal(await page.locator('[data-point-id]').inputValue(), chosen);
                    await page.locator('[data-points-close]').click();
                    const courierOn = await page.locator('[data-delivery-points]').getAttribute('data-courier-enabled') === '1';
                    assert.equal(await page.locator('[data-delivery-courier]').isVisible(), courierOn);
                    assert.equal(await page.locator('[name="delivery_method"][value="stavropol_courier"]').isDisabled(), !courierOn);
                    assert.equal(await page.locator('[data-point-id]').inputValue(), chosen);
                    assert.equal(await page.locator('[name="delivery_manual"]').count(), 0);
                }, width);
            });
        }
        await t.test('a refused promo code keeps the chosen point; courier is offered only in Stavropol', async () => {
            await withCheckout(browser, async (page, baseUrl) => {
                const quoted = [];
                await page.route('**/delivery/points?*', (route) => route.fulfill({ json: pointResponse(route) }));
                await page.route('**/delivery/city/geocode', (route) => route.fulfill({ status: 429, json: {} }));
                await page.route('**/delivery/quote', (route) => {
                    const body = route.request().postDataJSON();
                    quoted.push(`${body.delivery_point_method}:${body.delivery_point_id}`);
                    return route.fulfill({ json: body.delivery_point_method === 'stavropol_courier'
                        ? { token: 'courier-quote', price: 19900, price_label: '199 ₽', courier: true, note: 'Привезём сегодня.' }
                        : { token: 'card-quote', price: 30000, price_label: '300 ₽', days_min: 2, days_max: 3 } });
                });
                await page.goto(`${baseUrl}/checkout`);
                if (await page.locator('[data-cookie-choice="necessary"]').isVisible()) {
                    await page.locator('[data-cookie-choice="necessary"]').click();
                }
                await page.locator('[data-points-search]').focus();
                await page.keyboard.press('Enter');
                await page.waitForFunction(() => document.querySelector('[data-points-dialog]').open && document.querySelectorAll('.pickup-item').length === 3);
                await page.locator('.pickup-item').first().click();
                const chosen = await page.locator('[data-point-id]').inputValue();
                const method = await page.locator('[data-point-method]').inputValue();
                assert.ok(chosen && method);

                await page.locator('#promo_code').fill('NOSUCHCODE');
                await Promise.all([page.waitForURL(`${baseUrl}/checkout`, { waitUntil: 'load' }), page.locator('[formaction$="/checkout/promo"]').click()]);
                await page.waitForLoadState('load');
                assert.ok(await page.locator('[data-checkout-promo] .field-error').count());
                assert.equal(await page.locator('[data-point-id]').inputValue(), chosen);
                assert.equal(await page.locator('[data-point-method]').inputValue(), method);
                assert.equal(await page.locator('[name="delivery_method"][value="pickup_point"]').isChecked(), true);
                assert.match(await page.locator('[data-pickup-choice]').textContent(), /Проверочный ПВЗ/);
                if (await page.locator('[data-delivery-quote]').count()) {
                    await page.waitForFunction(() => document.querySelector('[name="delivery_quote_token"]').value === 'card-quote');
                    assert.equal(quoted.at(-1), `${method}:${chosen}`);
                }

                if (await page.locator('[data-delivery-points]').getAttribute('data-courier-enabled') === '1') {
                    assert.equal(await page.locator('[data-delivery-courier]').isVisible(), true);
                    await page.locator('[name="delivery_method"][value="stavropol_courier"]').check();
                    await page.waitForFunction(() => document.querySelector('[name="delivery_quote_token"]').value === 'courier-quote');
                    assert.equal(quoted.at(-1), 'stavropol_courier:');
                    assert.equal(await page.locator('[data-checkout-delivery-price]').textContent(), '199 ₽');
                    assert.equal(await page.locator('[data-delivery-quote-note]').textContent(), 'Привезём сегодня.');
                    assert.equal(await page.locator('[data-delivery-quote-note]').isVisible(), true);
                    assert.equal(await page.locator('[name="delivery_address"]').isVisible(), true);

                    await page.locator('[data-city-open]').click();
                    await page.locator('[data-popular-city="Краснодар"]').click();
                    assert.equal(await page.locator('[data-delivery-courier]').isVisible(), false);
                    assert.equal(await page.locator('[name="delivery_method"][value="stavropol_courier"]').isChecked(), false);
                    assert.equal(await page.locator('[name="delivery_quote_token"]').inputValue(), '');
                }
            });
        });
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
