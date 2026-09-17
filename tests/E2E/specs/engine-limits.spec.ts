/**
 * Wave 4 — browser-engine limitations that force a skip elsewhere.
 *
 * Every skip of a whole engine must be backed by a SENTINEL here that proves
 * the limitation still exists. When a Playwright image bump or a WordPress
 * release fixes the engine behaviour, the sentinel goes red — that is the
 * signal to delete the skip and the sentinel together. Without it, a skip
 * silently outlives its reason and the coverage gap becomes permanent.
 */
import { test, expect } from '../fixtures';
import { adminUrls } from '../helpers/spio';

test.describe('Engine limits — WebKit', () => {
	test.skip(({ browserName }) => browserName !== 'webkit', 'WebKit-only sentinels');

	test.beforeEach(async ({ spio }) => {
		await spio.reset();
	});

	/**
	 * SENTINEL for the webkit skip in ai-editor.spec.ts.
	 *
	 * Playwright's WebKit (webkit-2203, Linux) never finishes LAYOUT on
	 * WordPress core's attachment edit screen. The server sends the full
	 * page, but anything that forces layout — a getBoundingClientRect(),
	 * Playwright's own visibility checks, screencast painting — blocks the
	 * main thread forever. In a standalone probe a plain DOM query still
	 * answered right after load; inside the test runner (video + trace
	 * screencast painting) the thread is often frozen before even that, so
	 * this sentinel proves "the page arrived" from the network response. Bisected 2026-09-16: still
	 * hangs with every SPIO script and stylesheet blocked, and with SPIO
	 * deactivated entirely → core page + engine, not SPIO. Chromium and
	 * Firefox are unaffected. Not reproducible against real Safari from this
	 * stack (Linux-only WebKit build).
	 *
	 * FLIP when this fails with "laid-out": remove the skip in
	 * ai-editor.spec.ts, run it on webkit, delete this test.
	 */
	test('SENTINEL: layout on the WP attachment edit screen never completes', async ({ page, spio }) => {
		const { id } = await spio.uploadFixture('fixture-small.jpg');
		const response = await page.goto(adminUrls.editAttachment(id));

		// Sentinel: the server really sent the edit screen, so a "hung"
		// verdict below can't be a missing or broken page. Checked on the
		// NETWORK response on purpose: inside the runner the page's main
		// thread can already be frozen by the time a DOM query would run
		// (a plain getElementById evaluate hung here on 2026-09-16), while
		// reading the response body never touches that thread.
		expect(response?.status(), 'the attachment edit screen must be served').toBe(200);
		expect(await response!.text(), 'the edit screen markup must contain the image header').toContain(`media-head-${id}`);

		// Every call into the page races a timer: a hang must produce the
		// "hung" verdict in seconds, never consume the 90s test timeout.
		const outcome = await Promise.race([
			page
				.evaluate((itemId) => document.getElementById(`media-head-${itemId}`)?.getBoundingClientRect().height ?? -1)
				.then(() => 'laid-out')
				// The page is torn down with this evaluate still pending.
				.catch(() => 'closed'),
			new Promise<string>((resolve) => setTimeout(() => resolve('hung'), 10_000)),
		]);
		expect(
			outcome,
			'WebKit laid the attachment edit screen out: remove the webkit skip in ai-editor.spec.ts and delete this sentinel',
		).toBe('hung');
	});
});
