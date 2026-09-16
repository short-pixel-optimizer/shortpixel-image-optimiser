/**
 * Wave 0 gate — proves the harness end to end:
 *   1. the provisioned install is reachable and the admin session works;
 *   2. the SPIO settings page renders with ZERO JS errors and sane layout;
 *   3. a Media Library optimize round-trips through the REAL plugin
 *      pipeline (browser → Web Worker → admin-ajax → queue → mock API →
 *      download → meta write → cell re-render);
 *   4. the console-error tripwire really fails a test (an expected-to-fail
 *      spec throws an uncaught error and Playwright reports the failure as
 *      the expected outcome — if the tripwire ever went blind, this spec
 *      would "unexpectedly pass" and the run would go red).
 */
import { test, expect } from '../fixtures';
import { adminUrls, expectNoHorizontalOverflow, expectSettingsStylesheetApplied } from '../helpers/spio';

test.describe('Wave 0 smoke', () => {
	test.beforeEach(async ({ spio }) => {
		await spio.reset();
	});

	test('admin dashboard loads for the stored admin session', async ({ page }) => {
		await page.goto(adminUrls.dashboard);
		await expect(page.locator('#wpadminbar')).toBeVisible();
		await expect(page.locator('#wp-admin-bar-my-account')).toContainText(/admin/i);
	});

	test('SPIO settings page renders without JS errors and with its stylesheet applied', async ({ page }) => {
		await page.goto(adminUrls.settings);

		const wrap = page.locator('.wrap.is-shortpixel-settings-page');
		await expect(wrap).toBeVisible();

		// Tab sections are rendered (the settings JS wires them up on load).
		await expect(page.locator('section.setting-tab[data-part]').first()).toBeAttached();

		await expectSettingsStylesheetApplied(page);
		await expectNoHorizontalOverflow(page);
	});

	test('Media Library optimize round-trips through the mock ShortPixel API', async ({ page, spio }) => {
		const { id } = await spio.uploadFixture('fixture-small.jpg');

		const before = await spio.attachment(id);
		expect(before.optimized, 'precondition: freshly uploaded image is not optimized').toBe(false);

		await page.goto(adminUrls.mediaList);
		const cell = page.locator(`#shortpixel-data-${id}`);
		await expect(cell).toBeVisible();

		// SENTINEL: this page must OWN the processor lock, otherwise the queue
		// never advances past the first tick and the wait below would time out
		// with a misleading message. Both halves of SPIO's lock (server
		// transient + localStorage key) are cleared by reset()/auth setup.
		await expect
			.poll(() => page.evaluate(() => (window as any).ShortPixelProcessor?.isActive === true), {
				message: 'the page must be the active SPIO processor (stale bulk-secret lock?)',
			})
			.toBe(true);

		// The per-item "Optimize now" action (a javascript: link rendered by
		// UiHelper::getAction('optimize')) kicks the processor for this item.
		const optimizeLink = cell.locator('a.optimize');
		await expect(optimizeLink).toBeVisible();
		await optimizeLink.click();

		// The cell re-renders (getItemView) once the item is done: status text
		// carries the savings and the restore action appears.
		await expect(cell).toContainText(/Reduced by/i, { timeout: 60_000 });
		await expect(cell).toHaveClass(/is-restorable/);

		// Server-side truth + proof the traffic went through the mock.
		const after = await spio.attachment(id);
		expect(after.optimized).toBe(true);

		const requests = await spio.mockRequests();
		expect(requests.some((r) => r.path.includes('reducer')), 'the mock API must have answered a reducer call').toBe(true);
		expect(requests.some((r) => r.path.startsWith('/f/')), 'the plugin must have downloaded the optimized bytes').toBe(true);
	});

});

/** Fire an uncaught error on the page, asynchronously (like a real bug would). */
async function injectUncaughtError(page: import('@playwright/test').Page): Promise<void> {
	await page.goto(adminUrls.dashboard);
	await page.evaluate(() => {
		setTimeout(() => {
			throw new Error('spio-e2e tripwire probe');
		}, 0);
	});
}

test.describe('Wave 0 smoke — tripwire proof of life', () => {
	test.describe('capture', () => {
		// Opt out of teardown enforcement so this test can PASS while asserting
		// that the fixture actually CAPTURED the injected error. If the tripwire
		// ever goes blind, this test fails.
		test.use({ allowConsoleErrors: true });

		test('the tripwire captures an injected uncaught error', async ({ page, consoleErrors }) => {
			await injectUncaughtError(page);
			await expect.poll(() => consoleErrors.length, { timeout: 5_000 }).toBeGreaterThan(0);
			expect(consoleErrors.join('\n')).toContain('spio-e2e tripwire probe');
		});
	});

	test('the tripwire fails a test that leaks an uncaught error', async ({ page }) => {
		// Expected to FAIL at fixture teardown. Playwright reports an expected
		// failure as OK and an unexpected PASS as a failure — so if enforcement
		// ever stops working, this test goes red.
		test.fail(true, 'proof-of-life: the tripwire must turn the injected error into a failure');
		await injectUncaughtError(page);
		await page.waitForTimeout(250);
	});
});
