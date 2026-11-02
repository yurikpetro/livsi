const assert = require('node:assert/strict');
const { test } = require('node:test');
const fs = require('node:fs/promises');
const { chromium } = require('playwright');

const markup = `<span data-checkout-total data-items-total="100000">1000</span>
<span data-checkout-delivery-price></span><p data-checkout-delivery-note></p>
<form data-delivery-quote-url="http://livsi.test/delivery/quote">
<input name="_token" value="test-csrf"><input type="radio" name="delivery_method" value="pickup_point" checked>
<input type="radio" name="delivery_method" value="stavropol_courier">
<input name="delivery_city" value="Москва"><input name="delivery_region" value="Москва">
<input name="delivery_point_id" value="42"><input name="delivery_point_method" value="cdek_point">
<div data-delivery-quote><p data-delivery-quote-status role="status"></p>
<input name="delivery_quote_token">
<button type="button" data-delivery-recalculate>Рассчитать доставку</button></div>
<button type="submit">Оплатить</button></form>`;

test('Delivery quote browser lifecycle', async (t) => {
    const browser = await chromium.launch({ executablePath: process.env.LIVSI_TEST_BROWSER_PATH });
    const source = await fs.readFile('resources/js/delivery-quote.js', 'utf8');
    async function withForm(run, response, html = markup) {
        const context = await browser.newContext();
        const page = await context.newPage();
        const errors = [];
        page.on('pageerror', (error) => errors.push(error.message));
        await page.route('http://livsi.test/checkout', (route) => route.fulfill({ contentType: 'text/html', body: html }));
        await page.route('**/delivery/quote', response || ((route) => route.fulfill({ json: {
            token: 'server-token', price: 34567, price_label: '345,67 ₽', days_min: 2, days_max: 4,
        } })));
        try {
            await page.goto('http://livsi.test/checkout');
            await page.clock.install();
            await page.addScriptTag({ type: 'module', content: `${source}\ninitDeliveryQuote();` });
            await run(page);
            assert.deepEqual(errors, []);
        } finally { await context.close(); }
    }
    try {
        await t.test('promo delivery stays free before selection, while loading, after expiry and when changing points', async () => {
            let release;
            const gate = new Promise(resolve => { release = resolve; });
            let requests = 0;
            const freeMarkup = markup.replace('name="delivery_point_id" value="42"', 'name="delivery_point_id" value=""')
                .replace('</form>', '<div data-free-shipping="1"></div></form>');
            await withForm(async (page) => {
                assert.equal(await page.locator('[data-checkout-delivery-price]').textContent(), 'Бесплатно');
                assert.match(await page.locator('[data-checkout-delivery-note]').textContent(), /уточнить срок/);
                assert.match(await page.locator('[data-delivery-quote-status]').textContent(), /Доставка бесплатная/);
                assert.equal(requests, 0);
                assert.equal(await page.locator('[name="delivery_quote_token"]').inputValue(), '');
                await page.locator('button[type="submit"]').click();
                assert.equal(page.url(), 'http://livsi.test/checkout');
                await page.evaluate(() => {
                    document.querySelector('[name="delivery_point_id"]').value = '42';
                    document.querySelector('form').dispatchEvent(new CustomEvent('livsi:delivery-selection'));
                });
                await page.waitForFunction(() => document.querySelector('[data-delivery-quote-status]').textContent.includes('Уточняем срок'));
                assert.equal(await page.locator('[data-checkout-delivery-price]').textContent(), 'Бесплатно');
                release();
                await page.waitForFunction(() => document.querySelector('[name="delivery_quote_token"]').value === 'free-token');
                assert.match(await page.locator('[data-delivery-quote-status]').textContent(), /2–4 календарных дня/);
                assert.match(await page.locator('[data-checkout-total]').textContent(), /1\s*000,00/);
                await page.clock.fastForward(600001);
                assert.equal(await page.locator('[name="delivery_quote_token"]').inputValue(), '');
                assert.equal(await page.locator('[data-checkout-delivery-price]').textContent(), 'Бесплатно');
                assert.match(await page.locator('[data-delivery-quote-status]').textContent(), /сроке устарели/);
                await page.evaluate(() => {
                    document.querySelector('[name="delivery_point_id"]').value = '';
                    document.querySelector('form').dispatchEvent(new CustomEvent('livsi:delivery-selection'));
                });
                assert.equal(await page.locator('[data-checkout-delivery-price]').textContent(), 'Бесплатно');
                assert.match(await page.locator('[data-delivery-quote-status]').textContent(), /Выберите пункт/);
            }, async route => {
                requests++;
                await gate;
                await route.fulfill({ json: { token: 'free-token', price: 0, price_label: 'Бесплатно', days_min: 2, days_max: 4 } });
            }, freeMarkup);
        });
        await t.test('a failed carrier request keeps promo delivery free but still requires confirmation before payment', async () => {
            await withForm(async page => {
                await page.waitForFunction(() => document.querySelector('[data-delivery-quote-status]').textContent.includes('Недоступен'));
                assert.equal(await page.locator('[data-checkout-delivery-price]').textContent(), 'Бесплатно');
                assert.match(await page.locator('[data-checkout-delivery-note]').textContent(), /Не удалось подтвердить/);
                assert.equal(await page.locator('[name="delivery_quote_token"]').inputValue(), '');
                assert.equal(await page.locator('[data-delivery-recalculate]').isVisible(), true);
                await page.locator('button[type="submit"]').click();
                assert.equal(page.url(), 'http://livsi.test/checkout');
            }, route => route.fulfill({ status: 422, json: { errors: { delivery_quote: ['Недоступен тариф'] } } }),
            markup.replace('</form>', '<div data-free-shipping="1"></div></form>'));
        });
        await t.test('price is included and changing the selection clears it', async () => {
            await withForm(async (page) => {
                await page.waitForFunction(() => document.querySelector('[name="delivery_quote_token"]').value === 'server-token');
                assert.match(await page.locator('[data-checkout-total]').textContent(), /1\s*345,67/);
                assert.equal(await page.locator('[data-checkout-delivery-price]').textContent(), '345,67 ₽');
                assert.match(await page.locator('[data-checkout-delivery-note]').textContent(), /включены товары и доставка/);
                assert.match(await page.locator('[data-delivery-quote-status]').textContent(), /2–4 календарных дня/);
                assert.equal(await page.locator('[data-delivery-recalculate]').isVisible(), false);
                await page.evaluate(() => {
                    document.querySelector('[name="delivery_point_id"]').value = '';
                    document.querySelector('form').dispatchEvent(new CustomEvent('livsi:delivery-selection'));
                });
                assert.equal(await page.locator('[name="delivery_quote_token"]').inputValue(), '');
                assert.match(await page.locator('[data-checkout-total]').textContent(), /1\s*000,00/);
                assert.equal(await page.locator('[data-checkout-delivery-price]').textContent(), 'Не рассчитана');
                await page.locator('[name="delivery_method"][value="stavropol_courier"]').check();
                await page.locator('form').dispatchEvent('livsi:delivery-selection');
                assert.equal(await page.locator('[data-checkout-delivery-price]').textContent(), 'Не рассчитана');
                assert.match(await page.locator('[data-delivery-quote-status]').textContent(), /временно недоступен/);
            });
        });
        await t.test('zero tariff is shown as free delivery and leaves the items total unchanged', async () => {
            await withForm(async (page) => {
                await page.waitForFunction(() => document.querySelector('[name="delivery_quote_token"]').value === 'free-token');
                assert.equal(await page.locator('[data-checkout-delivery-price]').textContent(), 'Бесплатно');
                assert.match(await page.locator('[data-checkout-total]').textContent(), /1\s*000,00/);
                assert.match(await page.locator('[data-checkout-delivery-note]').textContent(), /включены товары и доставка/);
            }, (route) => route.fulfill({ json: { token: 'free-token', price: 0, price_label: '0 ₽' } }));
        });
        await t.test('quote expires and retry obtains a new price', async () => {
            await withForm(async (page) => {
                await page.waitForFunction(() => document.querySelector('[name="delivery_quote_token"]').value === 'server-token');
                await page.clock.fastForward(600001);
                assert.equal(await page.locator('[name="delivery_quote_token"]').inputValue(), '');
                assert.match(await page.locator('[data-delivery-quote-status]').textContent(), /устарел/);
                assert.equal(await page.locator('[data-checkout-delivery-price]').textContent(), 'Не рассчитана');
                assert.match(await page.locator('[data-checkout-total]').textContent(), /1\s*000,00/);
                assert.equal(await page.locator('[data-delivery-recalculate]').isVisible(), true);
                await page.locator('[data-delivery-recalculate]').click();
                await page.waitForFunction(() => document.querySelector('[name="delivery_quote_token"]').value === 'server-token');
            });
        });
        await t.test('API error prevents payment and offers retry without a manual fallback', async () => {
            await withForm(async (page) => {
                await page.waitForFunction(() => document.querySelector('[data-delivery-quote-status]').textContent.includes('Недоступен'));
                assert.equal(await page.locator('[name="delivery_quote_token"]').inputValue(), '');
                assert.match(await page.locator('[data-checkout-total]').textContent(), /1\s*000,00/);
                assert.equal(await page.locator('[data-delivery-recalculate]').isVisible(), true);
                await page.locator('button[type="submit"]').click();
                assert.equal(await page.locator('[data-checkout-delivery-price]').textContent(), 'Не рассчитана');
                assert.match(await page.locator('[data-delivery-quote-status]').textContent(), /дождитесь расчёта/);
                assert.equal(await page.locator('[name="delivery_manual"]').count(), 0);
                assert.equal(page.url(), 'http://livsi.test/checkout');
            }, (route) => route.fulfill({ status: 422, json: { errors: { delivery_quote: ['Недоступен тариф'] } } }));
        });
        await t.test('late quote from previous point cannot replace current selection', async () => {
            let release;
            const gate = new Promise((resolve) => { release = resolve; });
            let requests = 0;
            await withForm(async (page) => {
                await page.waitForFunction(() => document.querySelector('[data-delivery-quote-status]').textContent.includes('Рассчитываем'));
                await page.locator('button[type="submit"]').click();
                assert.match(await page.locator('[data-delivery-quote-status]').textContent(), /Дождитесь расчёта/);
                assert.equal(page.url(), 'http://livsi.test/checkout');
                assert.equal(await page.locator('[data-checkout-delivery-price]').textContent(), 'Рассчитываем…');
                assert.match(await page.locator('[data-checkout-total]').textContent(), /1\s*000,00/);
                await page.evaluate(() => {
                    document.querySelector('[name="delivery_point_id"]').value = '43';
                    document.querySelector('form').dispatchEvent(new CustomEvent('livsi:delivery-selection'));
                });
                await page.waitForFunction(() => document.querySelector('[name="delivery_quote_token"]').value === 'new-token');
                release();
                assert.equal(await page.locator('[name="delivery_quote_token"]').inputValue(), 'new-token');
                assert.match(await page.locator('[data-checkout-total]').textContent(), /1\s*200,00/);
                assert.equal(await page.locator('[data-checkout-delivery-price]').textContent(), '200 ₽');
            }, async (route) => {
                requests++;
                const first = route.request().postDataJSON().delivery_point_id === '42';
                if (first) await gate;
                await route.fulfill({ json: { token: first ? 'old-token' : 'new-token', price: first ? 50000 : 20000,
                    price_label: first ? '500 ₽' : '200 ₽' } }).catch(() => {});
            });
            assert.ok(requests >= 2);
        });
    } finally { await browser.close(); }
});
