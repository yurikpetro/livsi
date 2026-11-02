const assert = require('node:assert/strict');
const { test } = require('node:test');
const { chromium } = require('playwright');

async function swipe(page, from, to, { cancel = false } = {}) {
    const session = await page.context().newCDPSession(page);
    try {
        await session.send('Input.dispatchTouchEvent', { type: 'touchStart', touchPoints: [{ x: from[0], y: from[1], id: 1 }] });
        await session.send('Input.dispatchTouchEvent', { type: 'touchMove', touchPoints: [{ x: to[0], y: to[1], id: 1 }] });
        await session.send('Input.dispatchTouchEvent', { type: cancel ? 'touchCancel' : 'touchEnd', touchPoints: [] });
    } finally {
        await session.detach();
    }
}

async function activeLine(page) {
    return page.locator('[data-hero-slide]:not([hidden])').getAttribute('data-hero-slide');
}

test('Hero mobile swipes and autoplay', async (t) => {
    assert.ok(process.env.LIVSI_TEST_BASE_URL, 'Set LIVSI_TEST_BASE_URL');
    const browser = await chromium.launch({ executablePath: process.env.LIVSI_TEST_BROWSER_PATH });
    async function withPage(run, { width = 390, reducedMotion = 'no-preference' } = {}) {
        const context = await browser.newContext({ viewport: { width, height: 844 }, hasTouch: true, reducedMotion });
        const page = await context.newPage();
        const errors = [];
        page.on('pageerror', (error) => errors.push(error.message));
        try {
            const now = new Date('2026-10-15T12:00:00Z');
            await page.clock.install({ time: now });
            await page.clock.pauseAt(now);
            await page.goto(process.env.LIVSI_TEST_BASE_URL);
            await page.locator('.hero-swipe-ready').waitFor();
            if (await page.locator('[data-cookie-choice="necessary"]').isVisible()) {
                await page.locator('[data-cookie-choice="necessary"]').click();
            }
            await run(page);
            assert.deepEqual(errors, []);
        } finally {
            await context.close();
        }
    }
    try {
        await t.test('mobile hides tabs, cycles every five seconds and leaves the shelf unchanged', () => withPage(async (page) => {
            assert.equal(await page.locator('.hero-lines').isVisible(), false);
            const shelf = await page.locator('[data-shelf-line]:visible').count();
            await page.clock.runFor(4999);
            assert.equal(await activeLine(page), 'fresh');
            await page.clock.runFor(1);
            assert.equal(await activeLine(page), 'sweet');
            assert.equal(await page.locator('[data-shelf-line]:visible').count(), shelf);
            for (const expected of ['warm', 'base', 'pro', 'fresh']) {
                await page.clock.fastForward(5000);
                assert.equal(await activeLine(page), expected);
            }
        }));
        await t.test('swipes work both ways, wrap and restart the five-second timer', () => withPage(async (page) => {
            await page.clock.runFor(4000);
            await swipe(page, [310, 350], [90, 350]);
            assert.equal(await activeLine(page), 'sweet');
            await page.clock.runFor(4999);
            assert.equal(await activeLine(page), 'sweet');
            await page.clock.runFor(1);
            assert.equal(await activeLine(page), 'warm');
            await swipe(page, [90, 350], [310, 350]);
            assert.equal(await activeLine(page), 'sweet');
            await swipe(page, [90, 350], [310, 350]);
            await swipe(page, [90, 350], [310, 350]);
            assert.equal(await activeLine(page), 'pro');
        }));
        await t.test('short and cancelled gestures do not switch slides; vertical scrolling works', () => withPage(async (page) => {
            await swipe(page, [300, 350], [275, 350]);
            assert.equal(await activeLine(page), 'fresh');
            await swipe(page, [310, 350], [90, 350], { cancel: true });
            assert.equal(await activeLine(page), 'fresh');
            await swipe(page, [200, 550], [200, 250]);
            assert.equal(await activeLine(page), 'fresh');
            assert.ok(await page.evaluate(() => window.scrollY > 0));
        }));
        await t.test('autoplay pauses during a touch and while the banner is offscreen', () => withPage(async (page) => {
            const session = await page.context().newCDPSession(page);
            await session.send('Input.dispatchTouchEvent', { type: 'touchStart', touchPoints: [{ x: 200, y: 350, id: 1 }] });
            await page.clock.fastForward(10000);
            assert.equal(await activeLine(page), 'fresh');
            await session.send('Input.dispatchTouchEvent', { type: 'touchCancel', touchPoints: [] });
            await session.detach();
            await page.locator('[data-product-shelf]').scrollIntoViewIfNeeded();
            await page.evaluate(() => window.scrollTo(0, document.querySelector('[data-product-shelf]').offsetTop));
            await page.clock.runFor(100);
            await page.clock.fastForward(10000);
            assert.equal(await activeLine(page), 'fresh');
            await page.evaluate(() => window.scrollTo(0, 0));
            await page.clock.runFor(100);
            await page.clock.fastForward(5000);
            assert.equal(await activeLine(page), 'sweet');
        }));
        await t.test('reduced motion keeps manual swipes and disables autoplay', () => withPage(async (page) => {
            await page.clock.fastForward(10000);
            assert.equal(await activeLine(page), 'fresh');
            if (process.env.LIVSI_TEST_SCREENSHOT_PATH) {
                await page.screenshot({ path: process.env.LIVSI_TEST_SCREENSHOT_PATH });
            }
            await swipe(page, [310, 350], [90, 350]);
            assert.equal(await activeLine(page), 'sweet');
        }, { reducedMotion: 'reduce' }));
        await t.test('a normal CTA tap still opens the selected line after a swipe', () => withPage(async (page) => {
            await swipe(page, [310, 350], [90, 350]);
            await page.clock.runFor(1800);
            await page.locator('[data-hero-slide="sweet"] .hero-cta').tap();
            await page.waitForURL('**/catalog?line=sweet');
        }));
        await t.test('desktop tabs and shelf filtering remain; resizing starts and stops autoplay', () => withPage(async (page) => {
            assert.equal(await page.locator('.hero-lines').isVisible(), true);
            await page.clock.fastForward(10000);
            assert.equal(await activeLine(page), 'fresh');
            await page.locator('[data-hero-select="sweet"]').click();
            assert.equal(await activeLine(page), 'sweet');
            assert.equal(await page.locator('[data-shelf-line]:visible:not([data-shelf-line="sweet"])').count(), 0);
            await page.setViewportSize({ width: 390, height: 844 });
            await page.locator('[data-hero-select="sweet"]').evaluate((button) => button.blur());
            await page.clock.runFor(100);
            await page.clock.fastForward(5000);
            assert.equal(await activeLine(page), 'warm');
            await page.setViewportSize({ width: 1440, height: 844 });
            await page.clock.runFor(100);
            await page.clock.fastForward(10000);
            assert.equal(await activeLine(page), 'warm');
        }, { width: 1440 }));
    } finally {
        await browser.close();
    }
});
