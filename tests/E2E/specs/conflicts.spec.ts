/**
 * Wave 3 — third-party JS conflict registry.
 *
 * Each "hostile snippet" in tests/E2E/mu-plugins/hostile-snippets/ mimics a
 * class of third-party admin script that has broken WordPress plugins in
 * the wild (see each file's header for the real-world precedent). The
 * support endpoint injects the chosen snippet inline at admin_print_scripts
 * priority 0 — i.e. BEFORE SPIO's own scripts — and the same three core
 * flows are driven with it active:
 *   1. settings: switch a tab and save;
 *   2. media list: optimize one image through the real pipeline;
 *   3. bulk: a short bulk run.
 *
 * The console-error tripwire does most of the detecting; the functional
 * assertions make sure a flow that "quietly" stops working (no error, no
 * effect — the #62 pattern) is caught as well.
 *
 * Every snippet × flow combination is expected to PASS: SPIO must be robust
 * against these environments. A failing combination is a real finding —
 * convert it into a pin (see pin62 in settings.spec.ts for the pattern)
 * rather than skipping it.
 *
 * `window-url-overwrite` is excluded here: it is already pinned (#62).
 */
import { test, expect } from '../fixtures';
import { BulkPage } from '../helpers/bulk-page';
import { MediaList } from '../helpers/media-list';
import { SettingsPage } from '../helpers/settings-page';

// `jquery-noconflict` is handled by its own pinned describe block below.
const SNIPPETS = ['prototype-pollution', 'console-clobber'] as const;

for (const snippet of SNIPPETS) {
	test.describe(`hostile environment: ${snippet}`, () => {
		test.beforeEach(async ({ spio }) => {
			await spio.reset();
			await spio.setHostileSnippets([snippet]);
		});

		/** SENTINEL: the snippet must actually have run on this page. */
		async function expectSnippetActive(page: import('@playwright/test').Page): Promise<void> {
			const list = await page.evaluate(() => (window as any).__spioE2EHostile as string[] | undefined);
			expect(list, `hostile snippet ${snippet} must be injected`).toContain(snippet);
		}

		test(`settings: tab switch + save still work (${snippet})`, async ({ page, spio }) => {
			const settings = new SettingsPage(page);
			await settings.goto();
			await expectSnippetActive(page);

			await settings.switchTab('webp');
			await settings.setSwitch('createWebp', true);
			await settings.save('webp');

			await settings.goto('webp');
			await expect(settings.switchInput('createWebp')).toBeChecked();
			expect(Number((await spio.getSettings()).createWebp)).toBe(1);
		});

		test(`media list: optimize round-trips (${snippet})`, async ({ page, spio }) => {
			const { id } = await spio.uploadFixture('fixture-small.jpg');
			const list = new MediaList(page);
			await list.goto();
			await expectSnippetActive(page);

			await list.cell(id).locator('a.optimize').click();
			await list.expectOptimized(id);
			expect((await spio.attachment(id)).optimized).toBe(true);
		});

		test(`bulk: a short run completes (${snippet})`, async ({ page, spio }) => {
			const a = (await spio.uploadFixture('fixture-small.jpg')).id;
			const b = (await spio.uploadFixture('fixture-small.png')).id;
			const bulk = new BulkPage(page);
			await bulk.goto();
			await expectSnippetActive(page);

			await bulk.startSelection();
			await bulk.calculate();
			await bulk.startBulk();
			await bulk.driveToFinish(spio);
			expect(await bulk.statNumber('media', 'done')).toBe(2);
			expect((await spio.attachment(a)).optimized).toBe(true);
			expect((await spio.attachment(b)).optimized).toBe(true);
			await bulk.finish();
		});
	});
}

/**
 * PIN (unnumbered — E2E seed finding 2026-09-15): jQuery.noConflict(true)
 * released by another script kills SPIO's admin JS.
 *
 * res/js/shortpixel.js is jQuery-only and reads the `jQuery` global
 * LAZILY: `delayedInit()` (:7-16, re-armed every 10s), every UI handler in
 * `ShortPixel.*` (bulk-option injection, dropdown menus, comparer), and
 * `screen-media.js`'s `jQuery('.imgedit-menu')` / `image-editor-ui-ready`.
 * A theme or plugin calling `jQuery.noConflict(true)` after SPIO's scripts
 * were parsed (to load its own copy) therefore produces:
 *   - "jQuery is not a function" uncaught errors on every SPIO screen;
 *   - `console.error('ShortPixel: Delayed Init…')` from the 10s fallback;
 *   - and the QUIET failure that matters: the processor never becomes the
 *     active runner on the media list, so per-item optimize and the bulk
 *     page do nothing — no error is shown to the user (#62 pattern).
 * SPIO's own dependency declarations don't protect it: `shortpixel-onboarding`
 * does not even declare jQuery, and nothing captures a local reference
 * (`var $ = jQuery` at load time) that would survive a later release.
 *
 * FLIP-when-fixed (capture jQuery at load time in shortpixel.js /
 * screen-media.js, or feature-detect before each use): expect no "jQuery
 * is not a function" errors, the processor active, optimize + bulk green —
 * i.e. move `jquery-noconflict` into SNIPPETS above and delete this block.
 */
test.describe('hostile environment: jquery-noconflict (pinned)', () => {
	test.use({ allowConsoleErrors: true });

	test.beforeEach(async ({ spio }) => {
		await spio.reset();
		await spio.setHostileSnippets(['jquery-noconflict']);
	});

	test('pin: settings and media list break when jQuery is released (pinned_for_deferred_fix)', async ({ page, spio, consoleErrors }) => {
		const { id } = await spio.uploadFixture('fixture-small.jpg');

		// Settings page: the save handler itself is vanilla, but the page
		// throws on the lazy jQuery reads around it.
		const settings = new SettingsPage(page);
		await settings.goto();
		// SENTINEL: the snippet ran and jQuery is really gone.
		expect(await page.evaluate(() => (window as any).__spioE2EHostile)).toContain('jquery-noconflict');
		expect(await page.evaluate(() => typeof (window as any).jQuery)).toBe('undefined');

		// Media list: the processor never becomes active → optimize is dead.
		await page.goto('/wp-admin/upload.php?mode=list');
		await expect(page.locator(`#shortpixel-data-${id}`)).toBeVisible();
		await page.waitForTimeout(4_000); // give the processor its chance to start
		const active = await page.evaluate(() => (window as any).ShortPixelProcessor?.isActive === true);
		expect(active, 'PIN: the page never becomes the active processor without jQuery').toBe(false);

		await page.locator(`#shortpixel-data-${id} a.optimize`).click();
		await page.waitForTimeout(6_000);
		expect((await spio.attachment(id)).optimized, 'PIN: optimize silently does nothing').toBe(false);

		// The errors are there for anyone who opens the console — nowhere else.
		expect(consoleErrors.join('\n')).toMatch(/jQuery is not a function/);
	});
});
