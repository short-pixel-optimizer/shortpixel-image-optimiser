/**
 * Wave 3 — Custom / Other Media + the comparer popup.
 *
 * Custom media is the second image pipeline (CustomImageModel / the
 * 'custom' queues). Covers: the screen is gated by the showCustomMedia
 * setting; adding a folder through the real folder picker (tree rooted at
 * ABSPATH); the server refusing a Media Library year folder; refreshing a
 * folder; scanning; optimizing a custom file through the pipeline; the
 * comparer popup (with its first-click race worked around); and removing a
 * folder through the native confirm(). Plus one PIN for a defect the
 * exploration surfaced (rejected adds render their notices twice).
 */
import { test, expect } from '../fixtures';
import { CustomMedia } from '../helpers/custom-media';
import { MediaList, expectProcessorActive } from '../helpers/media-list';
import { adminUrls } from '../helpers/spio';

const FOLDER = 'e2e-custom';
const RELPATH = `wp-content/uploads/${FOLDER}/`;

/** Add the seeded folder via the picker and return its row's folder id. */
async function addSeededFolder(page: import('@playwright/test').Page, custom: CustomMedia): Promise<number> {
	await custom.goto('folders');
	await custom.openPicker();
	await custom.selectPath(RELPATH);
	await custom.confirmAdd();
	await expect(custom.pickerModal).toBeHidden();
	const row = page.locator('.shortpixel-other-media .item[class*="item-"]', { hasText: FOLDER }).first();
	await expect(row).toBeVisible();
	const id = Number(((await row.getAttribute('class')) || '').match(/item-(\d+)/)?.[1]);
	expect(id).toBeGreaterThan(0);
	return id;
}

test.describe('Custom Media', () => {
	test.beforeEach(async ({ spio }) => {
		await spio.reset();
		await spio.setSettings({ showCustomMedia: 1 });
	});

	test('the screen only exists when showCustomMedia is on', async ({ page, spio, consoleErrors }) => {
		await spio.setSettings({ showCustomMedia: 0 });
		// An unregistered admin page is a genuine HTTP 403 — the browser logs
		// that as a resource error; it is the expected outcome here.
		const response = await page.goto(adminUrls.customMedia);
		expect(response?.status()).toBe(403);
		await expect(page.locator('body')).toContainText(/not allowed to access this page/i);
		await expect(page.locator('#menu-media a[href="upload.php?page=wp-short-pixel-custom"]')).toHaveCount(0);
		consoleErrors.splice(
			0,
			consoleErrors.length,
			...consoleErrors.filter((e) => !/status of 403/.test(e)),
		);

		await spio.setSettings({ showCustomMedia: 1 });
		const custom = new CustomMedia(page);
		await custom.goto('files');
		await expect(page.locator('#menu-media a[href="upload.php?page=wp-short-pixel-custom"]')).toBeVisible();
	});

	test('a folder is added through the picker, refreshed, scanned and its file optimized', async ({ page, spio }) => {
		await spio.createCustomFolder({ name: FOLDER, fixtures: ['fixture-small.jpg', 'fixture-small.png'] });
		const custom = new CustomMedia(page);
		const folderId = await addSeededFolder(page, custom);

		// The row reflects the two files found by the initial refresh.
		await expect(custom.folderRow(folderId).locator('.files-number')).toContainText(/2/);

		// Refresh: no new files.
		await custom.clickFolderAction(folderId, /Refresh Folder/);
		await expect(custom.folderRow(folderId).locator('.status')).toContainText(/No new files found/i, { timeout: 30_000 });

		// Scan tab loops through all folders to completion.
		await custom.goto('scan');
		await custom.scanAllFolders();

		// Files tab lists both, unoptimized; optimize one through the pipeline.
		await custom.goto('files', `&folder_id=${folderId}`);
		await expectProcessorActive(page);
		const ids = await custom.fileIds();
		expect(ids).toHaveLength(2);
		const target = ids[0];
		await custom.cell(target).locator('a.optimize').click();
		await expect(custom.cell(target)).toContainText(/Reduced by/i, { timeout: 60_000 });
		await expect(custom.page.locator(`.item.item-${target} input[name="select[]"]`)).toHaveClass(/\bis-restorable\b/);
	});

	test('the comparer opens for an optimized custom file and shows both images', async ({ page, spio }) => {
		await spio.createCustomFolder({ name: FOLDER, fixtures: ['fixture-small.jpg'] });
		const custom = new CustomMedia(page);
		const folderId = await addSeededFolder(page, custom);
		await custom.goto('files', `&folder_id=${folderId}`);
		await expectProcessorActive(page);
		const [id] = await custom.fileIds();
		await custom.cell(id).locator('a.optimize').click();
		await expect(custom.cell(id)).toContainText(/Reduced by/i, { timeout: 60_000 });

		// Work around the comparer's first-click race: loadComparer() only
		// opens the popup if jquery.twentytwenty.js has ALREADY finished
		// loading when the data request returns. Pre-warm the script.
		await page.evaluate(
			() =>
				new Promise<void>((resolve) => {
					(window as any).jQuery.getScript((window as any).ShortPixel.WP_PLUGIN_URL + '/res/js/jquery.twentytwenty.js', () => resolve());
				}),
		);
		await custom.cell(id).locator('button.sp-dropbtn').click();
		await page.locator(`#sp-dd-${id} a.comparer`).click();

		const modal = page.locator('#spUploadCompare');
		await expect(modal).toBeVisible({ timeout: 15_000 });
		await expect(modal.locator('.sp-modal-title')).toContainText(/Compare Images/i);
		const original = modal.locator('img.spUploadCompareOriginal');
		const optimized = modal.locator('img.spUploadCompareOptimized');
		await expect(original).toHaveAttribute('src', /\.jpg/);
		await expect(optimized).toHaveAttribute('src', /\.jpg/);
		expect(await original.getAttribute('src'), 'original comes from the backup, not the optimized file').not.toBe(await optimized.getAttribute('src'));
		await expect(modal.locator('.twentytwenty-handle')).toBeVisible();

		await modal.locator('button.sp-close-button').click();
		await expect(modal).toBeHidden();
	});

	test('Media Library year folders are greyed out and the uploads root is refused', async ({ page, spio }) => {
		// The Media Library has at least one upload → a year dir exists, and
		// an optimized image → the ShortpixelBackups dir exists too.
		const { id } = await spio.uploadFixture('fixture-small.jpg');
		const list = new MediaList(page);
		await list.goto();
		await list.cell(id).locator('a.optimize').click();
		await list.expectOptimized(id);

		const custom = new CustomMedia(page);
		await custom.goto('folders');
		await custom.openPicker();
		await custom.clickNode('wp-content/');
		await custom.clickNode('wp-content/uploads/');
		// Year folders (Media Library) are greyed out and cannot be selected.
		const year = custom.pickerModal.locator('li.folder[data-relpath^="wp-content/uploads/20"]').first();
		await expect(year).toHaveClass(/\bis_active\b/);
		// The backups folder is greyed out as well.
		await expect(custom.pickerModal.locator('li.folder[data-relpath="wp-content/uploads/ShortpixelBackups/"]')).toHaveClass(/\bis_active\b/);

		// The uploads root itself is selectable but refused server-side: the
		// recursive check hits a forbidden child (the backups dir comes
		// first). The modal shows the generic message, the specific reason
		// arrives as an admin notice rendered into the modal.
		await expect(custom.pickerModal.locator('input.select-folder')).toBeEnabled();
		await custom.confirmAdd();
		await expect(custom.pickerModal.locator('.folder-message')).toBeVisible();
		await expect(custom.pickerModal.locator('.folder-message')).toContainText(/Failed to add Folder/i);
		await expect(page.locator('.shortpixel-notice').filter({ hasText: /ShortPixel Backups|Media Library/i }).first()).toBeVisible();
		await expect(custom.pickerModal, 'the modal stays open on failure').toBeVisible();
		// Nothing was registered.
		await custom.goto('folders');
		await expect(custom.folderRows).toHaveCount(0);
	});

	test('stop monitoring confirms and removes the folder row', async ({ page, spio }) => {
		await spio.createCustomFolder({ name: FOLDER, fixtures: ['fixture-small.jpg'] });
		const custom = new CustomMedia(page);
		const folderId = await addSeededFolder(page, custom);

		page.once('dialog', (dialog) => {
			expect(dialog.message()).toMatch(/stop optimizing this folder/i);
			void dialog.accept();
		});
		await custom.clickFolderAction(folderId, /Stop Monitoring/);
		await expect(custom.folderRow(folderId)).toHaveCount(0, { timeout: 30_000 });

		// Server truth: the row is gone from the listing after a reload too.
		await custom.goto('folders');
		await expect(custom.folderRow(folderId)).toHaveCount(0);
	});
});

test.describe('Custom Media — pinned', () => {
	test.beforeEach(async ({ spio }) => {
		await spio.reset();
		await spio.setSettings({ showCustomMedia: 1 });
	});

	/**
	 * PIN (unnumbered — E2E seed finding): screen-custom.js UpdateFolderViewEvent
	 * (~:479-488) contains the identical `if (data.display_notices) {
	 * this.AppendNotices(…) }` block twice, so every REJECTED "Add folder"
	 * renders its admin notice(s) twice inside the picker modal.
	 * FLIP-when-fixed: expect exactly one notice per rejection.
	 */
	test('pin: a rejected add renders its notice twice (pinned_for_deferred_fix)', async ({ page, spio }) => {
		await spio.uploadFixture('fixture-small.jpg'); // guarantees a year dir
		const custom = new CustomMedia(page);
		await custom.goto('folders');
		await custom.openPicker();
		await custom.clickNode('wp-content/');
		await custom.clickNode('wp-content/uploads/');
		await custom.confirmAdd();
		await expect(custom.pickerModal.locator('.folder-message')).toBeVisible();

		const notices = page.locator('.shortpixel-notice');
		const count = await notices.count();
		// SENTINEL: at least one notice was rendered at all.
		expect(count, 'sentinel: the rejection produced a notice').toBeGreaterThan(0);
		expect(count % 2, 'PIN: notices are appended twice (duplicate block)').toBe(0);
	});
});
