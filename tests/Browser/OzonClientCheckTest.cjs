const assert = require('node:assert/strict');
const { test } = require('node:test');
const fs = require('node:fs/promises');
const { chromium } = require('playwright');

test('Ozon recipient verification browser lifecycle', async (t) => {
    const browser = await chromium.launch({ executablePath: process.env.LIVSI_TEST_BROWSER_PATH });
    const source = await fs.readFile('resources/js/ozon-client-check.js', 'utf8');
    const markup = `<form><input name="_token" value="test-csrf"><input name="phone">
        <input name="delivery_method" value="pickup_point"><input name="delivery_point_method" value="ozon_point">
        <span data-ozon-check-status></span>
        <div data-delivery-points data-ozon-check-url="http://livsi.test/check"><dialog data-points-dialog></dialog>
        <p data-ozon-check-status></p><button type="button" data-ozon-check-retry hidden>Повторить</button></div></form>`;
    async function withForm(run, respond) {
        const context = await browser.newContext();
        const page = await context.newPage();
        const errors = [];
        page.on('pageerror', (error) => errors.push(error.message));
        await page.route('http://livsi.test/form', (route) => route.fulfill({ contentType: 'text/html', body: markup }));
        await page.route('http://livsi.test/check', respond);
        try {
            await page.goto('http://livsi.test/form');
            await page.clock.install();
            await page.addScriptTag({ type: 'module', content: `${source}\nconst root = document.querySelector('[data-delivery-points]'); initOzonClientCheck(root, allowed => { root.dataset.allowed = String(allowed); });` });
            await run(page);
            assert.deepEqual(errors, []);
        } finally { await context.close(); }
    }
    const allowed = (page) => page.locator('[data-delivery-points]').getAttribute('data-allowed');
    const status = (page) => page.locator('[data-ozon-check-status]').first().textContent();
    const fillPhone = async (page, value) => {
        await page.locator('[name="phone"]').fill(value);
        await page.clock.runFor(601);
    };
    try {
        await t.test('normalizes and debounces a valid number; equivalent formatting does not refetch', async () => {
            let requests = 0;
            await withForm(async (page) => {
                assert.equal(await allowed(page), 'false');
                await fillPhone(page, '90000');
                assert.equal(requests, 0);
                await fillPhone(page, '8 (900) 000-00-00');
                await page.waitForFunction(() => document.querySelector('[data-delivery-points]').dataset.allowed === 'true');
                await fillPhone(page, '+7 900 000-00-00');
                assert.equal(requests, 1);
            }, (route) => {
                requests++;
                assert.equal(route.request().postDataJSON().phone, '+79000000000');
                return route.fulfill({ json: { can_be_delivered: true } });
            });
        });
        await t.test('confirmed denial blocks Ozon and keeps other carriers usable', async () => {
            await withForm(async (page) => {
                await fillPhone(page, '+79000000000');
                await page.waitForFunction(() => document.querySelector('[data-ozon-check-status]').textContent.includes('не подтвердил'));
                assert.equal(await allowed(page), 'false');
                assert.equal(await page.locator('[data-ozon-check-retry]').isVisible(), false);
                assert.equal(await page.evaluate(() => document.querySelector('form').dispatchEvent(new Event('submit', { cancelable: true }))), false);
                await page.locator('[name="delivery_point_method"]').fill('cdek_point');
                assert.equal(await page.evaluate(() => document.querySelector('form').dispatchEvent(new Event('submit', { cancelable: true }))), true);
            }, (route) => route.fulfill({ json: { can_be_delivered: false } }));
        });
        await t.test('outage is unknown, can be retried, and never claims the number is unregistered', async () => {
            let attempts = 0;
            await withForm(async (page) => {
                await fillPhone(page, '+79000000000');
                await page.locator('[data-ozon-check-retry]').waitFor({ state: 'visible' });
                assert.equal(await allowed(page), 'false');
                assert.match(await status(page), /Не удалось проверить/);
                assert.doesNotMatch(await status(page), /не зарегистрирован|не подтвердил/);
                await page.locator('[data-ozon-check-retry]').click();
                await page.clock.runFor(1);
                await page.waitForFunction(() => document.querySelector('[data-delivery-points]').dataset.allowed === 'true');
                assert.equal(attempts, 2);
            }, (route) => ++attempts === 1 ? route.fulfill({ status: 503, json: {} }) : route.fulfill({ json: { can_be_delivered: true } }));
        });
        await t.test('editing revokes approval immediately and invalid country-prefix numbers are not sent', async () => {
            let attempts = 0;
            await withForm(async (page) => {
                await fillPhone(page, '+79000000000');
                await page.waitForFunction(() => document.querySelector('[data-delivery-points]').dataset.allowed === 'true');
                await page.locator('[name="phone"]').fill('+79000000001');
                assert.equal(await allowed(page), 'false');
                await page.locator('[name="phone"]').fill('+70000000000');
                await page.clock.runFor(601);
                assert.equal(attempts, 1);
                assert.equal(await allowed(page), 'false');
            }, (route) => { attempts++; return route.fulfill({ json: { can_be_delivered: true } }); });
        });
        await t.test('a late approval for the old phone cannot enable Ozon for the new number', async () => {
            let requests = 0;
            let release;
            const gate = new Promise(resolve => { release = resolve; });
            await withForm(async (page) => {
                const requested = page.waitForRequest('http://livsi.test/check');
                await fillPhone(page, '+79000000000');
                await requested;
                await page.locator('[name="phone"]').dispatchEvent('change');
                await page.clock.runFor(1);
                assert.equal(requests, 1);
                await fillPhone(page, '+79000000001');
                await page.waitForFunction(() => document.querySelector('[data-ozon-check-status]').textContent.includes('не подтвердил'));
                release();
                assert.equal(await allowed(page), 'false');
                assert.match(await status(page), /не подтвердил/);
                assert.equal(requests, 2);
            }, async (route) => {
                requests++;
                const old = route.request().postDataJSON().phone === '+79000000000';
                if (old) await gate;
                await route.fulfill({ json: { can_be_delivered: old } }).catch(() => {});
            });
        });
        await t.test('a stalled request times out and offers retry', async () => {
            let release;
            const gate = new Promise(resolve => { release = resolve; });
            await withForm(async (page) => {
                const requested = page.waitForRequest('http://livsi.test/check');
                await fillPhone(page, '+79000000000');
                await requested;
                await page.clock.fastForward(15001);
                await page.locator('[data-ozon-check-retry]').waitFor({ state: 'visible' });
                assert.equal(await allowed(page), 'false');
                assert.match(await status(page), /Не удалось проверить/);
                release();
            }, async (route) => { await gate; await route.fulfill({ json: { can_be_delivered: true } }).catch(() => {}); });
        });
    } finally { await browser.close(); }
});
