/**
 * Bulk page.
 *
 * Covers the happy path dashboard → selection → summary → process → finished
 * → dashboard for a small Media Library, pause/resume and stop during
 * processing, and the error path when the API rejects every image
 * (forced -106): fatal rows in the error box and the fatal counter.
 *
 * Timing: the process loop is driven by BulkPage.driveToFinish(), which ages
 * the queue rows between processor ticks so "sent to API" items are re-picked
 * immediately instead of after ShortQ's 10s process_timeout.
 */
import { test, expect } from '../fixtures';
import { BulkPage } from '../helpers/bulk-page';
import { ApiCode, expectNoHorizontalOverflow } from '../helpers/spio';

const FIXTURES = ['fixture-small.jpg', 'fixture-small.png', 'fixture-large.jpg'];

test.describe('Bulk page', () => {
	test.beforeEach(async ({ spio }) => {
		await spio.reset();
	});

	test('a full bulk run optimizes every unoptimized image', async ({ page, spio }) => {
		const ids: number[] = [];
		for (const name of FIXTURES) {
			ids.push((await spio.uploadFixture(name)).id);
		}

		const bulk = new BulkPage(page);
		await bulk.goto();
		await bulk.expectPanel('dashboard');
		await expectNoHorizontalOverflow(page);

		await bulk.startSelection();
		await expect(page.locator('#media_checkbox')).toBeChecked();
		await bulk.calculate();

		// Summary numbers come from the prepared queue.
		expect(await bulk.statNumber('media', 'in_queue'), 'all uploaded images are queued').toBe(ids.length);
		expect(await bulk.statNumber('total', 'images-total_images_without_ai')).toBeGreaterThanOrEqual(ids.length);

		await bulk.startBulk();
		await bulk.driveToFinish(spio);

		expect(await bulk.statNumber('media', 'done'), 'finished panel: done count').toBe(ids.length);
		expect(await bulk.statNumber('media', 'fatal_errors')).toBe(0);
		for (const id of ids) {
			expect((await spio.attachment(id)).optimized, `attachment ${id} optimized`).toBe(true);
		}

		await bulk.finish();
	});

	test('pause and resume during processing', async ({ page, spio }) => {
		for (const name of FIXTURES) {
			await spio.uploadFixture(name);
		}
		// Keep the API "waiting" a few rounds so the run is long enough to pause.
		await spio.setMock({ waitingRounds: 3 });

		const bulk = new BulkPage(page);
		await bulk.goto();
		await bulk.startSelection();
		await bulk.calculate();
		await bulk.startBulk();

		await bulk.pause();
		await expect(page.locator('#ResumeBulkButton')).toBeVisible();
		await expect(page.locator('#PauseBulkButton')).toBeHidden();

		await bulk.resume();
		await expect(page.locator('#PauseBulkButton')).toBeVisible();

		await spio.setMock({ waitingRounds: 0 });
		await bulk.driveToFinish(spio);
		expect(await bulk.statNumber('media', 'fatal_errors')).toBe(0);
		await bulk.finish();
	});

	test('stop confirms, finishes the bulk server-side and returns to the dashboard', async ({ page, spio }) => {
		for (const name of FIXTURES) {
			await spio.uploadFixture(name);
		}
		await spio.setMock({ waitingRounds: 5 });

		const bulk = new BulkPage(page);
		await bulk.goto();
		await bulk.startSelection();
		await bulk.calculate();
		await bulk.startBulk();

		// stop() waits for the server to report both queues clear, then
		// asserts the panel that server state entails: "finished" when the
		// run completed anything before the stop landed, "dashboard" when
		// it did not. Which one happens is a timing race (see stop()), so
		// the test drives it to the dashboard rather than assuming it.
		const landed = await bulk.stop(spio);
		if ('finished' === landed) {
			// Finish clears the completed-run summary — the same button a
			// user clicks — and must return to the dashboard.
			await bulk.finish();
		}

		// Either way we end on the dashboard, and it stays that way.
		await bulk.goto();
		await bulk.expectPanel('dashboard');
	});

});

test.describe('Bulk page — API error path', () => {
	// The pinned defect below IS a console.error; assert on it instead of tripping.
	test.use({ allowConsoleErrors: true });

	test.beforeEach(async ({ spio }) => {
		await spio.reset();
	});

	/**
	 * PIN (bulk error rows):
	 * screen-bulk.js HandleItemError() (~:727) expects the failed item wrapped
	 * as `result.result`, but the processor hands it the item object itself
	 * (`{item_id, message, is_error, is_done, …}`), so EVERY API error takes
	 * the "unknown" fallback branch (~:763-766): `fatal` is never set — the
	 * error rows are rendered without the `.fatal` class (no red styling,
	 * `kblink` help icon never shown) — and a `console.error('Error without
	 * item - …')` fires per failed image. The counters and the row text are
	 * correct, so the user-visible damage is cosmetic plus console noise.
	 *
	 * FLIP-when-fixed (read `result` directly when `result.result` is absent):
	 *   expect rows `div.fatal` count >= ids.length, expect NO
	 *   "Error without item" console errors, drop allowConsoleErrors + suffix.
	 */
	test('API errors are counted and listed, rows not marked fatal, console.error per item (pinned_for_deferred_fix)', async ({
		page,
		spio,
		consoleErrors,
	}) => {
		const ids: number[] = [];
		for (const name of FIXTURES) {
			ids.push((await spio.uploadFixture(name)).id);
		}
		await spio.setMock({ forceStatusCode: ApiCode.UNREACHABLE });

		const bulk = new BulkPage(page);
		await bulk.goto();
		await bulk.startSelection();
		await bulk.calculate();
		await bulk.startBulk();
		await bulk.driveToFinish(spio);

		// Working parts: server-side counters and the per-item messages.
		expect(await bulk.statNumber('media', 'fatal_errors'), 'every image failed terminally').toBe(ids.length);
		expect(await bulk.statNumber('media', 'done')).toBe(0);

		const rows = await bulk.showErrors();
		await expect(rows.first()).toBeVisible();
		expect(await rows.count(), 'one error row per failed image').toBeGreaterThanOrEqual(ids.length);
		await expect(rows.first()).toContainText(/URL inaccessible/i);

		for (const id of ids) {
			expect((await spio.attachment(id)).optimized).toBe(false);
		}

		// PINNED: the rows never get the fatal class …
		expect(
			await page.locator('section.panel.active .errorbox.media > div.fatal').count(),
			'PIN: HandleItemError takes the "unknown" branch, so rows are not marked .fatal',
		).toBe(0);
		// … and SPIO logs one "Error without item" per failed image.
		const unknownBranch = consoleErrors.filter((e) => /Error without item/.test(e));
		expect(unknownBranch.length, 'PIN: console.error("Error without item") per failed item').toBeGreaterThanOrEqual(ids.length);
		// SENTINEL: nothing ELSE went wrong on the page.
		expect(consoleErrors.filter((e) => !/Error without item/.test(e))).toEqual([]);

		await bulk.finish();
	});

	/**
	 * PIN (queue full): when the API answers -404 (its optimization queue is
	 * full) or -500 (maintenance), OptimizeController sets QUEUE_FULL (-8) on
	 * the item result. screen-bulk.js HandleItemError() now recognises it on
	 * the unwrapped item and calls StopProcess({ defer: true }) with
	 * `timesEmpty = 10`, which schedules the next run after a long wait.
	 * But the same response then reaches shortpixel-processor.js
	 * HandleQueueStatus(): the queue still has items (QUEUE_ITEMS), so it
	 * resets `timesEmpty` to 0, restores the default interval and calls
	 * RunProcess(), which replaces the long timer with a short one. The bulk
	 * logs "queue is full" and asks the API again a few seconds later.
	 * @todo In shortpixel-processor.js HandleQueueStatus(): do not restart
	 *       the run while a queue-full defer is pending (e.g. a flag set by
	 *       the QUEUE_FULL branch and cleared by the deferred run).
	 * FLIP-when-fixed: within the window only one queue-full answer arrives.
	 */
	test('pin: a queue-full defer is undone by the queue status (pinned_for_deferred_fix)', async ({ page, spio, consoleErrors }) => {
		await spio.uploadFixture('fixture-small.jpg');
		await spio.setMock({ forceStatusCode: -404 });
		const logs: string[] = [];
		page.on('console', (m) => logs.push(m.text()));

		const bulk = new BulkPage(page);
		await bulk.goto();
		// Record every item error the bulk screen receives, with its time.
		await page.evaluate(() => {
			const screen = (window as any).ShortPixelProcessor.screen;
			const original = screen.HandleItemError.bind(screen);
			(window as any).__spioItemErrors = [];
			screen.HandleItemError = (result: any, type: string) => {
				(window as any).__spioItemErrors.push({ wrapped: typeof result.result !== 'undefined', error: result.error, at: Date.now() });
				return original(result, type);
			};
		});
		await bulk.startSelection();
		await bulk.calculate();
		await bulk.startBulk();

		// SENTINEL: the QUEUE_FULL code reaches the bulk screen, unwrapped.
		await expect
			.poll(() => page.evaluate(() => (window as any).__spioItemErrors.length), { timeout: 60_000, message: 'the queue-full answer must reach HandleItemError' })
			.toBeGreaterThan(0);
		const first = await page.evaluate(() => (window as any).__spioItemErrors[0]);
		expect(first.error, 'sentinel: the item carries QUEUE_FULL (-8)').toBe(-8);
		expect(first.wrapped, 'sentinel: the item reaches HandleItemError unwrapped').toBe(false);
		// SENTINELS (fixed part): the queue-full branch runs instead of the "unknown" one.
		expect(logs.some((l) => /queue is full/i.test(l)), 'sentinel: the bulk logs that it waits for the full queue').toBe(true);
		expect(consoleErrors.some((e) => /Error without item/.test(e)), 'sentinel: no "Error without item" for a full queue').toBe(false);

		// THE PIN: the defer is undone, so the API is asked again within seconds.
		await expect
			.poll(() => page.evaluate(() => (window as any).__spioItemErrors.length), {
				timeout: 40_000,
				message: 'PIN: fixed? No second queue-full answer within 40s — the bulk now really waits; flip this pin.',
			})
			.toBeGreaterThan(1);
	});
});
