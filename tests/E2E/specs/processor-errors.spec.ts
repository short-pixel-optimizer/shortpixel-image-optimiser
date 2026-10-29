/**
 * Processor worker: what the user sees when the processing request itself
 * fails at the network level (fetch() rejects — DNS, connection reset,
 * firewall, CORS / mixed-content from a site-URL mismatch, ad blocker).
 *
 * Mechanism under test (res/js/shortpixel-worker.js + screens/screen-base.js):
 * the worker's fetch().catch() relays the raw JavaScript error as the
 * message; the processor counts it as a worker error and hands it to
 * screen.HandleError(), which builds a red "ShortPixel encountered a fatal
 * error when optimizing images…" notice from `data.http_text`.
 *
 * Two things are meant to hold there:
 *
 *   1. The notice is developer-facing and only shows when SPIO debug mode
 *      is on: HandleError() / HandleErrorStop() return early unless the
 *      processor's BOOLEAN debugIsActive is exactly true.
 *   2. Known defect, still open (pinned below): the relayed error has no
 *      `http_text`, so with debug mode on the notice body is
 *      String(undefined) — the word "undefined" and nothing else.
 *
 * The failure is forced at the browser: the processing POST to
 * admin-ajax.php is aborted by a route, so fetch() rejects exactly as it
 * does on a real network failure. No server-side state is involved.
 *
 * The worker logs console.error on that path by design, so the tripwire is
 * relaxed and the console errors are asserted explicitly.
 */
import { test, expect } from '../fixtures';
import type { Page } from '@playwright/test';
import { adminUrls } from '../helpers/spio';
import { MediaList } from '../helpers/media-list';

const FATAL_NOTICE = '.notice.notice-error:has(.error-details)';
const FATAL_TITLE = /ShortPixel encountered a fatal error when optimizing images/i;

/**
 * Aborts every SPIO processor request (worker POSTs to admin-ajax.php with
 * an `action=shortpixel_…` body). Routed on the CONTEXT so the dedicated
 * worker's fetch is covered too.
 */
async function cutProcessorRequests(page: Page): Promise<void> {
	await page.context().route(
		(url) => /\/wp-admin\/admin-ajax\.php$/.test(url.pathname),
		async (route) => {
			const body = route.request().postData() || '';
			if (route.request().method() === 'POST' && /(^|&)action=shortpixel_/.test(body)) {
				await route.abort('connectionfailed');
				return;
			}
			await route.continue();
		},
	);
}

/** Waits until the processor has registered at least one worker failure. */
async function expectWorkerError(page: Page): Promise<void> {
	await expect
		.poll(() => page.evaluate(() => (window as any).ShortPixelProcessor?.workerErrors ?? 0), {
			message: 'the aborted processing request must reach the processor as a worker error',
			timeout: 30_000,
		})
		.toBeGreaterThanOrEqual(1);
}

test.describe('Processor — network failure of the processing request', () => {
	test.use({ allowConsoleErrors: true });

	test.beforeEach(async ({ spio }) => {
		await spio.reset();
	});

	/**
	 * Regression: with debug mode off the developer-facing notice stays
	 * hidden, whatever the worker reports.
	 */
	test('with debug mode off, a failed processing request shows no fatal-error notice', async ({
		page,
		spio,
		consoleErrors,
	}) => {
		const { id } = await spio.uploadFixture('fixture-small.jpg');
		const list = new MediaList(page);
		await list.goto();

		// Pre-condition: the E2E site never defines SHORTPIXEL_DEBUG, so the
		// processor must report debug mode OFF; otherwise the notice is legit.
		expect(
			await page.evaluate(() => (window as any).ShortPixelProcessor?.debugIsActive),
			'pre-condition: SPIO debug mode must be off for this page',
		).toBe(false);
		await expect(page.locator(FATAL_NOTICE)).toHaveCount(0);

		await cutProcessorRequests(page);
		await list.cell(id).locator('a.optimize').click();
		await expectWorkerError(page);

		// The worker reports the cut request; nothing else may blow up.
		expect(consoleErrors.length, 'the worker reports the aborted request on the console').toBeGreaterThan(0);
		expect(
			consoleErrors.filter((e) => !/Worker\.js reporting issue on catch|Failed to fetch|Load failed|NetworkError|ERR_CONNECTION/i.test(e)),
			'only the worker\'s own report of the cut request is expected',
		).toEqual([]);

		// Debug mode is off: the developer-facing notice must stay hidden.
		await expect(page.locator(FATAL_NOTICE), 'fatal-error notice shown although debug mode is off').toHaveCount(0);
	});

	/**
	 * PIN: res/js/shortpixel-worker.js fetch().catch() relays the raw error
	 * with no `http_text`, and HandleError() prints `data.http_text`, so with
	 * debug mode on the notice body is the literal String(undefined).
	 * @todo shortpixel-worker.js: fall back to error.message / the status
	 *       text when there is no HTTP response.
	 * FLIP-when-fixed: the body names the error and never equals "undefined".
	 * Drop the suffix.
	 */
	test('pin: with debug mode on, the fatal-error notice body is "undefined" (pinned_for_deferred_fix)', async ({
		page,
		spio,
	}) => {
		const { id } = await spio.uploadFixture('fixture-small.jpg');
		const list = new MediaList(page);
		// SHORTPIXEL_DEBUG in the request switches debug mode on for that page
		// load (administrators only), which is how support asks users to run it.
		await page.goto(adminUrls.mediaList + '&SHORTPIXEL_DEBUG=1');

		expect(
			await page.evaluate(() => (window as any).ShortPixelProcessor?.debugIsActive),
			'pre-condition: SPIO debug mode must be on for this page',
		).toBe(true);

		await cutProcessorRequests(page);
		await list.cell(id).locator('a.optimize').click();
		await expectWorkerError(page);

		// SENTINEL: with debug on the notice is legitimate and shows up.
		const notice = page.locator(FATAL_NOTICE).first();
		await expect(notice).toBeVisible({ timeout: 15_000 });
		await expect(notice).toContainText(FATAL_TITLE);

		// PINNED: the body is String(undefined) instead of the error.
		const details = (await notice.locator('.error-details').innerText()).trim();
		expect(details, 'PIN: fixed? the notice body names the error — flip this pin').toMatch(/^undefined$/i);
	});
});
