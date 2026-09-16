/**
 * Wave 1 — Media Library list view, SPIO column (Tier 1).
 *
 * Covers the per-item actions a user reaches from the list (optimize,
 * restore, re-optimize with another compression, mark/unmark completed,
 * retry after an API error), the injected bulk-action bar, the
 * Screen-Options column toggle (exploration proved WordPress only CSS-hides
 * the column, so bulk actions keep working) and the list-view status filter.
 *
 * All API traffic is answered by the mock; every test starts from reset().
 */
import { test, expect } from '../fixtures';
import { BulkOption, MediaList } from '../helpers/media-list';
import { ApiCode } from '../helpers/spio';

test.describe('Media Library list view', () => {
	test.beforeEach(async ({ spio }) => {
		await spio.reset();
	});

	test('restore from the burger menu puts the image back to the unoptimized state', async ({ page, spio }) => {
		const { id } = await spio.uploadFixture('fixture-small.jpg');
		const list = new MediaList(page);
		await list.goto();

		await list.cell(id).locator('a.optimize').click();
		await list.expectOptimized(id);
		expect((await spio.attachment(id)).optimized).toBe(true);

		const menu = await list.openMenu(id);
		await menu.locator('a.restore').click();

		// The confirmation text lands in the JS-created sibling, the cell
		// itself re-renders to the unoptimized shape (no "Restored" text there).
		await expect(list.message(id)).toContainText(/Item restored/i, { timeout: 60_000 });
		await list.expectUnoptimized(id);
		expect((await spio.attachment(id)).optimized, 'server-side: meta cleared by the restore').toBe(false);
	});

	test('re-optimize with another compression type updates the cell', async ({ page, spio }) => {
		const { id } = await spio.uploadFixture('fixture-small.jpg');
		const list = new MediaList(page);
		await list.goto();

		await list.cell(id).locator('a.optimize').click();
		await list.expectOptimized(id);
		await expect(list.cell(id)).toContainText(/\(Lossy\)/);

		const menu = await list.openMenu(id);
		await menu.locator('a.reoptimize-glossy').click();

		await expect(list.cell(id)).toContainText(/\(Glossy\)/, { timeout: 60_000 });
		await expect(list.cell(id)).toHaveAttribute('data-compression', '2');
		expect((await spio.attachment(id)).optimized).toBe(true);
	});

	test('mark as completed / unmark round-trips through the notice box', async ({ page, spio }) => {
		const { id } = await spio.uploadFixture('fixture-small.jpg');
		const list = new MediaList(page);
		await list.goto();

		await list.cell(id).locator('a.markCompleted').click();
		await expect(list.cell(id).locator('.shortpixel-image-notice')).toContainText(/marked as completed/i, {
			timeout: 30_000,
		});
		await expect(list.cell(id)).not.toHaveClass(/\bis-optimizable\b/);

		await list.cell(id).locator('.shortpixel-error-reset a').click(); // "Click to unmark as completed"
		await list.expectUnoptimized(id);
	});

	test('an API error is surfaced on the item and a retry succeeds', async ({ page, spio }) => {
		const { id } = await spio.uploadFixture('fixture-small.jpg');
		await spio.setMock({ forceStatusCode: ApiCode.UNREACHABLE });
		const list = new MediaList(page);
		await list.goto();

		await list.cell(id).locator('a.optimize').click();
		// The API's own message reaches the user; the item is not optimized.
		await expect(list.message(id)).toHaveClass(/\berror\b/, { timeout: 60_000 });
		// The API's own Status->Message is shown verbatim (mock: "URL inaccessible…").
		await expect(list.message(id)).toContainText(/URL inaccessible/i);
		expect((await spio.attachment(id)).optimized).toBe(false);

		// Fix the "cause", retry from whichever link the cell offers.
		await spio.setMock({ forceStatusCode: null });
		const cell = list.cell(id);
		const retry = cell.locator('.shortpixel-error-reset a, a.optimize').first();
		await expect(retry).toBeVisible();
		await retry.click();
		await list.expectOptimized(id);
	});

	test('the injected bulk action optimizes every selected item (top bar only)', async ({ page, spio }) => {
		const a = (await spio.uploadFixture('fixture-small.jpg')).id;
		const b = (await spio.uploadFixture('fixture-small.png')).id;
		const list = new MediaList(page);
		await list.goto();

		// SPIO's options are injected client-side into the WP bulk selects.
		await expect(page.locator('#bulk-action-selector-top option[value="shortpixel-optimize"]').first()).toBeAttached();

		await list.rowCheckbox(a).check();
		await list.rowCheckbox(b).check();
		await list.applyBulkAction(BulkOption.optimize);

		// Items fire one per second and uncheck themselves as they go.
		await expect(list.rowCheckbox(a)).not.toBeChecked({ timeout: 10_000 });
		await expect(list.rowCheckbox(b)).not.toBeChecked({ timeout: 10_000 });
		await list.expectOptimized(a, 90_000);
		await list.expectOptimized(b, 90_000);
	});

	test('hiding the column via Screen Options keeps bulk actions working (CSS-only hide)', async ({ page, spio }) => {
		const { id } = await spio.uploadFixture('fixture-small.jpg');
		const list = new MediaList(page);
		await list.goto();

		await list.setColumnVisible(false);
		try {
			await expect(list.cell(id)).toBeHidden();
			await expect(list.cell(id), 'WordPress keeps hidden columns in the DOM').toBeAttached();

			await list.rowCheckbox(id).check();
			await list.applyBulkAction(BulkOption.optimize);
			await expect(list.rowCheckbox(id)).not.toBeChecked({ timeout: 10_000 });
			await expect.poll(async () => (await spio.attachment(id)).optimized, { timeout: 60_000 }).toBe(true);
		} finally {
			// The preference is per-user meta and would leak into later tests
			// (reset() clears it too, belt and braces).
			await list.setColumnVisible(true);
		}
		await expect(list.cell(id)).toBeVisible();
	});

	test('the ShortPixel status filter narrows the list', async ({ page, spio }) => {
		const optimized = (await spio.uploadFixture('fixture-small.jpg')).id;
		const plain = (await spio.uploadFixture('fixture-small.png')).id;
		const list = new MediaList(page);
		await list.goto();
		await list.cell(optimized).locator('a.optimize').click();
		await list.expectOptimized(optimized);

		const filter = page.locator('select#shortpixel_status');
		await expect(filter).toBeVisible();
		await expect(filter.locator('option')).toHaveText(['Any ShortPixel State', 'Optimized', 'Unoptimized', 'Optimization Error']);

		// Submit the filter the way the Filter button does: AdminController::
		// selected_filter_value() only honours the dropdown when the request
		// also carries `filter_action` (the button's name).
		await filter.selectOption('optimized');
		await page.locator('#post-query-submit').click();
		await expect(page).toHaveURL(/shortpixel_status=optimized/);
		await expect(list.cell(optimized)).toBeVisible();
		await expect(list.cell(plain)).toHaveCount(0);

		await list.goto('&shortpixel_status=unoptimized&filter_action=Filter');
		await expect(list.cell(plain)).toBeVisible();
		await expect(list.cell(optimized)).toHaveCount(0);
	});
});
