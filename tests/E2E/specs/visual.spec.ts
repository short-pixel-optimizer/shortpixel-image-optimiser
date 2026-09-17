/**
 * Wave 4 — visual regression baselines (Playwright toHaveScreenshot).
 *
 * The functional specs prove SPIO's admin screens WORK; these prove they
 * still LOOK right. SPIO's settings UI is built from custom elements
 * (<settinglist>, <setting>, <switch>…) with zero user-agent styling, so a
 * stylesheet that fails to load, a SCSS refactor or a WordPress admin CSS
 * change collapses the layout while every click-level assertion still
 * passes. A pixel diff is the only thing that catches that class.
 *
 * Rules:
 *   - Runs in the `visual` project only (Chromium, 1366×768 unless a test
 *     resizes), and only inside the pinned Playwright Docker image — see
 *     `ignoreSnapshots` in playwright.config.ts. Baselines live in
 *     tests/E2E/snapshots/visual.spec.ts/.
 *   - ELEMENT screenshots of SPIO's own containers, never the full page:
 *     the WP admin bar, menu and footer ("Version x.y") change with every
 *     WordPress release and are not SPIO's to guard. Exception: the 780px
 *     captures are viewport shots (see that test). helpers/screenshot.css
 *     hides the fixed WP chrome and SPIO's parked save banner in every
 *     capture, because a capture taller than the viewport paints fixed
 *     elements into the element's pixels.
 *   - State that outlives a test is cleared by spio.reset() rather than
 *     masked (e.g. bulk history, which the settings overview prints as
 *     "The last bulk processing ran on: <date>").
 *   - Every capture waits for fonts and every <img> inside the element to
 *     finish loading, and parks the mouse so no :hover state leaks in.
 *   - Dynamic regions are masked explicitly and each mask says why.
 *   - Refresh after an INTENDED change: `bin/test-e2e.sh --project visual
 *     --update-snapshots`, then review the PNG diff in the commit like code.
 */
import type { Locator, Page } from '@playwright/test';
import { test, expect } from '../fixtures';
import { AiEditorModal, type EditorAction } from '../helpers/ai-editor';
import { BulkPage, type BulkPanel } from '../helpers/bulk-page';
import { SETTINGS_PARTS, SettingsPage } from '../helpers/settings-page';
import { adminUrls, type SpioSupport } from '../helpers/spio';

/** Wait until an element is visually settled: fonts, images, no hover. */
async function settle(page: Page, target: Locator): Promise<void> {
	await expect(target).toBeVisible();
	await page.mouse.move(0, 0);
	await page.evaluate(() => document.fonts.ready.then(() => undefined));
	await expect
		.poll(
			() =>
				target.evaluate((root) =>
					[...root.querySelectorAll('img')]
						.filter((img) => img.getAttribute('src'))
						.every((img) => img.complete),
				),
			{ message: 'every <img> in the captured element must have finished loading' },
		)
		.toBe(true);
}

/**
 * Settings page variant of settle(): additionally waits out the "saved"
 * banner. FormResponseEvent adds `show` for 2s after ANY settings AJAX
 * response (e.g. the simple/advanced mode switch), and a capture taken
 * inside that window freezes a transient banner into the baseline.
 */
async function settleSettings(page: Page, settings: SettingsPage): Promise<void> {
	await expect(settings.saveBanner, 'no transient save banner in a baseline').not.toHaveClass(/\bshow\b/, {
		timeout: 10_000,
	});
	return settle(page, settings.root);
}

/** Regions that legitimately differ between runs (none inside SPIO yet besides WP notices). */
function volatileMasks(page: Page): Locator[] {
	return [
		// Core/third-party admin notices (e.g. a WP update nag) get moved
		// into .wrap by WordPress JS; they are not SPIO's layout.
		page.locator('.notice:not(.shortpixel-notice), .update-nag'),
	];
}

test.describe('Visual — settings page', () => {
	test.beforeEach(async ({ spio }) => {
		await spio.reset();
	});

	test('every tab in advanced mode (desktop)', async ({ page }) => {
		const settings = new SettingsPage(page);
		await settings.goto();
		await settings.setViewMode('advanced');
		for (const part of SETTINGS_PARTS) {
			await settings.goto(part);
			await expect(settings.section(part)).toHaveClass(/\bactive\b/);
			await settleSettings(page, settings);
			await expect(settings.root, `settings tab "${part}"`).toHaveScreenshot(`settings-advanced-${part}.png`, {
				mask: volatileMasks(page),
			});
		}
	});

	test('overview tab in simple mode (desktop)', async ({ page }) => {
		const settings = new SettingsPage(page);
		await settings.goto('overview');
		await settings.setViewMode('simple');
		await settle(page, settings.root);
		await expect(settings.root).toHaveScreenshot('settings-simple-overview.png', { mask: volatileMasks(page) });
	});

	test('overview tab at 780px, menu closed and opened', async ({ page }) => {
		// 780px sits inside the 768/782/786 breakpoint band flagged in the
		// Wave 1 exploration — the width most likely to show a layout seam.
		// VIEWPORT captures here, not element ones: on mobile SPIO's header
		// is position:fixed, and an element capture taller than the viewport
		// scrolls the page and paints that fixed header over the cards — a
		// picture no user ever sees. The viewport at scroll 0 is what a
		// phone-width user actually gets.
		await page.setViewportSize({ width: 780, height: 1024 });
		const settings = new SettingsPage(page);
		await settings.goto('overview');
		await settle(page, settings.root);
		await expect(page).toHaveScreenshot('settings-780-overview.png', { mask: volatileMasks(page) });

		await settings.openMobileMenu();
		await settle(page, settings.root);
		await expect(page).toHaveScreenshot('settings-780-menu-open.png', { mask: volatileMasks(page) });
	});
});

test.describe('Visual — bulk page panels', () => {
	test.beforeEach(async ({ spio }) => {
		await spio.reset();
	});

	async function capturePanel(page: Page, bulk: BulkPage, name: BulkPanel): Promise<void> {
		const panel = bulk.panel(name);
		await settle(page, panel);
		await expect(panel, `bulk panel "${name}"`).toHaveScreenshot(`bulk-${name}.png`, { mask: volatileMasks(page) });
	}

	async function seedLibrary(spio: SpioSupport): Promise<void> {
		for (const name of ['fixture-small.jpg', 'fixture-small.png', 'fixture-large.jpg']) {
			await spio.uploadFixture(name);
		}
	}

	test('dashboard, selection, summary and finished', async ({ page, spio }) => {
		await seedLibrary(spio);
		const bulk = new BulkPage(page);
		await bulk.goto();
		await bulk.expectPanel('dashboard');
		await capturePanel(page, bulk, 'dashboard');

		await bulk.startSelection();
		await capturePanel(page, bulk, 'selection');

		await bulk.calculate();
		await expect(page.locator('[data-action="StartBulk"]')).toBeEnabled({ timeout: 30_000 });
		await capturePanel(page, bulk, 'summary');

		await bulk.startBulk();
		await bulk.driveToFinish(spio);
		await capturePanel(page, bulk, 'finished');
		await bulk.finish();
	});
});

test.describe('Visual — AI editor modal', () => {
	test.beforeEach(async ({ spio }) => {
		await spio.reset();
	});

	for (const action of ['remove', 'scale'] as EditorAction[]) {
		test(`${action === 'remove' ? 'background removal' : 'upscale'} modal (edit-media opener)`, async ({ page, spio }) => {
			const id = (await spio.uploadFixture('fixture-small.png')).id;
			await page.goto(adminUrls.editAttachment(id));
			await expect(page.locator(`#media-head-${id}`)).toBeVisible();

			const modal = new AiEditorModal(page);
			await modal.open(action);
			await modal.expectStyled();
			// Capture only after the auto-preview round-trip, so the spinner
			// state is final. The preview itself points at the mock's
			// api.shortpixel.com URL, which the hermetic browser blocks — the
			// preview area therefore renders empty, identically every run.
			await modal.expectPreviewLoaded();
			await settle(page, modal.popup);
			await expect(modal.popup).toHaveScreenshot(`ai-editor-${action}.png`, { mask: volatileMasks(page) });
			await modal.close();
		});
	}
});
