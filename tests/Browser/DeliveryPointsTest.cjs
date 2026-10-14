const assert = require('node:assert/strict');
const fs = require('node:fs/promises');
const path = require('node:path');
const { test } = require('node:test');
const { chromium } = require('playwright');

test('Overlapping pickup points show all carriers and select the correct point', async (t) => {
    const baseUrl = process.env.LIVSI_TEST_BASE_URL;
    assert.ok(baseUrl, 'Set LIVSI_TEST_BASE_URL to the storefront being tested');
    const browser = await chromium.launch({ executablePath: process.env.LIVSI_TEST_BROWSER_PATH });
    const address = 'Ставрополь, ул. 45-я Параллель, 26 <ПВЗ>';
    const coordinates = [45.012, 41.92];
    const points = {
        cdek_point: { id: '101', provider: 'cdek' },
        ozon_point: { id: '102', provider: 'ozonlog' },
        yandex_point: { id: '103', provider: 'yataxi' },
    };

    try {
        for (const width of [1440, 390]) {
            await t.test(`${width}px: cluster content and all carrier selections`, async () => {
                const context = await browser.newContext({ viewport: { width, height: 900 }, reducedMotion: 'reduce' });
                let csrf;
                try {
                    const catalog = await (await context.request.get(`${baseUrl}/catalog`)).text();
                    csrf = catalog.match(/name="_token"\s+value="([^"]+)"/)?.[1];
                    const variant = catalog.match(/name="variant_id"\s+value="(\d+)"/)?.[1];
                    assert.ok(csrf && variant, 'Catalog must contain the guest cart form');
                    await context.request.post(`${baseUrl}/cart/add`, { form: { _token: csrf, variant_id: variant, qty: '1' } });
                    await context.addInitScript(() => {
                        localStorage.setItem('livsi.delivery.city.v1', JSON.stringify({ city: 'Ставрополь', region: 'Ставропольский край' }));
                    });
                    const page = await context.newPage();
                    const errors = [];
                    page.on('pageerror', (error) => errors.push(error.message));
                    if (process.env.LIVSI_TEST_LOCAL_ASSETS === '1') {
                        const manifest = JSON.parse(await fs.readFile('public/build/manifest.json', 'utf8'));
                        await page.route('**/build/assets/app-*.js', (route) => route.fulfill({ path: 'resources/js/app.js', contentType: 'text/javascript' }));
                        await page.route('**/build/assets/app-*.css', (route) => route.fulfill({ path: `public/build/${manifest['resources/css/app.css'].file}`, contentType: 'text/css' }));
                        await page.route('**/img/delivery-*.svg', (route) => route.fulfill({ path: `public/img/${path.basename(new URL(route.request().url()).pathname)}`, contentType: 'image/svg+xml' }));
                    }
                    await page.route('**/delivery/points?*', (route) => {
                        const point = points[new URL(route.request().url()).searchParams.get('method')];
                        return route.fulfill({ json: {
                            points: point ? [{ ...point, address, city: 'Ставрополь', region: 'Ставропольский',
                                name: 'ПВЗ', timetable: 'Пн–Вс 10:00–20:00 <script>window.pickupInjected=true</script>',
                                lat: coordinates[0], lng: coordinates[1] }] : [],
                            has_more: false,
                        } });
                    });
                    await page.goto(`${baseUrl}/checkout`);
                    assert.equal(await page.locator('[name="delivery_city"]').inputValue(), 'Ставрополь');
                    await page.waitForFunction(() => window.ymaps?.Clusterer, null, { timeout: 60000 });
                    await page.evaluate(() => {
                        const Map = ymaps.Map;
                        const Clusterer = ymaps.Clusterer;
                        ymaps.Map = function (...args) { return window.pickupTestMap = new Map(...args); };
                        ymaps.Clusterer = function (...args) { return window.pickupTestClusterer = new Clusterer(...args); };
                        ymaps.Map.prototype = Map.prototype;
                        ymaps.Clusterer.prototype = Clusterer.prototype;
                    });
                    for (const [method, carrier] of [['cdek_point', 'СДЭК'], ['ozon_point', 'Ozon'], ['yandex_point', 'Яндекс']]) {
                        if (await page.locator('[data-points-dialog]').evaluate((dialog) => !dialog.open)) {
                            if (await page.locator('[name="delivery_method"][value="pickup_point"]').isChecked()) {
                                await page.locator('[data-points-search]').click();
                            } else {
                                await page.locator('[name="delivery_method"][value="pickup_point"]').check();
                            }
                        }
                        await page.waitForFunction(() => window.pickupTestClusterer?.getGeoObjects().length === 3);
                        await page.evaluate((center) => pickupTestMap.setCenter(center, 19), coordinates);
                        await page.waitForFunction(() => pickupTestClusterer.getClusters().length === 1);
                        await page.evaluate(() => pickupTestClusterer.balloon.open(pickupTestClusterer.getClusters()[0]));
                        const caption = page.getByText(`${carrier} · ${address}`, { exact: true });
                        await caption.waitFor({ state: 'visible' });
                        await caption.click();
                        const select = page.locator(`[data-pickup-balloon-id="${points[method].id}"]`);
                        await select.waitFor({ state: 'visible' });
                        assert.ok((await page.locator('.pickup-balloon-address:visible').textContent()).includes(address));
                        assert.equal(await page.evaluate(() => Boolean(window.pickupInjected)), false);
                        if (process.env.LIVSI_TEST_ARTIFACT_DIR) {
                            await fs.mkdir(process.env.LIVSI_TEST_ARTIFACT_DIR, { recursive: true });
                            await page.screenshot({ path: path.join(process.env.LIVSI_TEST_ARTIFACT_DIR, `pickup-cluster-${width}-${method}.png`) });
                        }
                        await select.click();
                        assert.equal(await page.locator('[data-point-id]').inputValue(), points[method].id);
                        assert.equal(await page.locator('[data-point-method]').inputValue(), method);
                        assert.equal(await page.locator('[data-points-dialog]').evaluate((dialog) => dialog.open), false);
                        assert.equal(await page.locator('[data-ozon-notice]').isVisible(), method === 'ozon_point');
                    }
                    await page.locator('[data-points-search]').click();
                    await page.waitForFunction(() => window.pickupTestClusterer?.getGeoObjects().length === 3);
                    await page.evaluate(async (center) => {
                        const marks = pickupTestClusterer.getGeoObjects();
                        pickupTestClusterer.removeAll();
                        marks.forEach((mark, index) => mark.geometry.setCoordinates([center[0], center[1] + (index - 1) * 0.0006]));
                        pickupTestClusterer.add(marks);
                        await pickupTestMap.setCenter(center, 18);
                    }, coordinates);
                    await page.waitForFunction(() => pickupTestClusterer.getClusters().length === 0);
                    const carrierIcons = page.locator('ymaps[style*="/img/delivery-"]');
                    await carrierIcons.first().waitFor({ state: 'visible' });
                    assert.equal(await carrierIcons.count(), 3);
                    for (const icon of await carrierIcons.all()) {
                        const box = await icon.boundingBox();
                        assert.equal(box.width, 27);
                        assert.equal(box.height, 27);
                    }
                    if (process.env.LIVSI_TEST_ARTIFACT_DIR) {
                        await page.locator('[data-points-map]').screenshot({ path: path.join(process.env.LIVSI_TEST_ARTIFACT_DIR, `pickup-carrier-icons-${width}.png`) });
                    }
                    assert.deepEqual(errors, []);
                } finally {
                    const cart = await (await context.request.get(`${baseUrl}/cart`)).text();
                    const items = new Set([...cart.matchAll(/action="([^"]*\/cart\/items\/\d+)"/g)].map((match) => match[1]));
                    for (const item of items) {
                        await context.request.post(item, { form: { _token: csrf, _method: 'DELETE' } });
                    }
                    await context.close();
                }
            });
        }
    } finally {
        await browser.close();
    }
});
