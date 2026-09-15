/**
 * Page object for SPIO's Custom / Other Media screen
 * (wp-admin/upload.php?page=wp-short-pixel-custom, tabs via ?part=).
 *
 * Facts (Wave 3 exploration):
 *   - the screen exists only when the `showCustomMedia` setting is on (the
 *     E2E seed forces it OFF → set it per test);
 *   - Folders tab: "Select" opens `.modal-folder-picker`, a tree rooted at
 *     ABSPATH whose nodes are `li.folder[data-relpath="wp-content/uploads/…/"]`
 *     (no leading slash, trailing slash). ONE click selects AND expands;
 *     greyed nodes carry `is_active` and ignore clicks. "Add" is
 *     `input.select-folder` (enabled once a node is selected);
 *   - server refuses year dirs under uploads ("Media Library"), the backup
 *     dir, unwritable dirs and subfolders of registered folders — a
 *     non-numeric child of uploads (support route custom-folder) passes;
 *   - folder rows `.item.item-<id>` with `.status`/`.files-number` and the
 *     actions "Refresh Folder" (no confirm) / "Stop Monitoring" (native
 *     confirm()) / "Show all Files";
 *   - Files tab: rows `.list-overview .item.item-<metaId>`, SPIO column
 *     `#shortpixel-data-<id>` with `a.optimize`, progress in the sibling
 *     `#shortpixel-message-<id>`; queue type 'custom';
 *   - Scan tab: `button.scan-button` loops scanNextFolder every 200ms; done
 *     when `.scan-area .result-table div.message` says all folders scanned.
 */
import { expect, type Locator, type Page } from '@playwright/test';
import { adminUrls, withSpioEvent } from './spio';

export class CustomMedia {
	constructor(readonly page: Page) {}

	async goto(part: 'files' | 'folders' | 'scan' = 'files', query = ''): Promise<void> {
		await this.page.goto(`${adminUrls.customMedia}&part=${part}${query}`);
		await expect(this.page.locator('div.wrap.shortpixel-other-media')).toBeVisible();
		await expect(this.page.locator('.custom-media-tabs a.selected')).toHaveAttribute('href', new RegExp(`part=${part}`));
	}

	// ---- Folders tab -------------------------------------------------

	get pickerModal(): Locator {
		return this.page.locator('.shortpixel-modal.modal-folder-picker');
	}

	async openPicker(): Promise<void> {
		await this.page.locator('a.button.open-selectfolder-modal').click();
		await expect(this.pickerModal).toBeVisible();
		// The root listing arrives via shortpixel.folder.LoadFolders.
		await expect(this.pickerModal.locator('.sp-folder-picker li.folder').first()).toBeVisible({ timeout: 30_000 });
	}

	treeNode(relpath: string): Locator {
		return this.pickerModal.locator(`li.folder[data-relpath="${relpath}"]`);
	}

	/** Click a node (selects it and expands its children, loaded via AJAX). */
	async clickNode(relpath: string): Promise<void> {
		const node = this.treeNode(relpath);
		await expect(node).toBeVisible();
		await withSpioEvent(this.page, 'shortpixel.folder.LoadFolders', () => node.locator('> a').click(), 30_000).catch(async () => {
			// A leaf folder loads no children (no LoadFolders round-trip).
		});
		await expect(node).toHaveClass(/\bselected\b/);
	}

	/** Walk the tree to a relpath like "wp-content/uploads/e2e-custom/" (each segment is clicked). */
	async selectPath(relpath: string): Promise<void> {
		const segments = relpath.split('/').filter(Boolean);
		let current = '';
		for (const segment of segments) {
			current += segment + '/';
			await this.clickNode(current);
		}
		await expect(this.pickerModal.locator('.sp-folder-picker-selected')).toContainText(relpath);
		await expect(this.pickerModal.locator('input.select-folder')).toBeEnabled();
	}

	/** Click "Add" and wait for the server's answer. */
	async confirmAdd(): Promise<void> {
		await withSpioEvent(this.page, 'shortpixel.folder.AddNewDirectory', () =>
			this.pickerModal.locator('input.select-folder').click(),
		);
	}

	folderRow(folderId: number): Locator {
		return this.page.locator(`.shortpixel-other-media .item.item-${folderId}`);
	}

	/** Row actions are hover-revealed (WP's .row-actions pattern): hover first, then click by text. */
	async clickFolderAction(folderId: number, text: RegExp): Promise<void> {
		const row = this.folderRow(folderId);
		await row.hover();
		const link = row.locator('.row-actions a', { hasText: text });
		await link.click({ force: true });
	}

	get folderRows(): Locator {
		return this.page.locator('.shortpixel-other-media .list-overview .item[class*="item-"]');
	}

	// ---- Files tab ---------------------------------------------------

	fileRows(): Locator {
		return this.page.locator('.list-overview .item[class*="item-"]');
	}

	cell(metaId: number): Locator {
		return this.page.locator(`#shortpixel-data-${metaId}`);
	}

	message(metaId: number): Locator {
		return this.page.locator(`#shortpixel-message-${metaId}`);
	}

	/** Meta ids of every file row on the page (from the select[] checkboxes). */
	async fileIds(): Promise<number[]> {
		return this.page.locator('.list-overview input[name="select[]"]').evaluateAll((els) => els.map((el) => Number((el as HTMLInputElement).value)));
	}

	// ---- Scan tab ----------------------------------------------------

	async scanAllFolders(): Promise<void> {
		await this.page.locator('.scan-actions button.scan-button[name="scan"]').click();
		await expect(this.page.locator('.scan-area .result-table div.message')).toContainText(/All Folders have been scanned/i, {
			timeout: 60_000,
		});
		await expect(this.page.locator('.scan-actions .action-stop')).toHaveClass(/\bnot-visible\b/);
	}
}
