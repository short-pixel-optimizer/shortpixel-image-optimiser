/**
 * Page object for the SPIO column in the Media Library LIST view
 * (wp-admin/upload.php?mode=list).
 *
 * Selector facts (Wave 1 exploration):
 *   - cell:        `#shortpixel-data-<id>` (`.sp-column-info`), re-rendered by
 *                  replacing outerHTML — always re-resolve through a Locator.
 *                  Capability classes: `is-optimizable`, `is-restorable`,
 *                  `ai-action`; attribute `data-compression="0|1|2"`.
 *   - direct links `a.optimize` ("Optimize Now"), `a.markCompleted`;
 *   - burger menu  `.sp-dropbtn` toggles `.sp-dropdown.sp-show`, holding
 *                  `a.restore`, `a.comparer`, `a.reoptimize-lossy|glossy|lossless`,
 *                  `a.reoptimize-smartcrop|smartcropless`, `a.optimizethumbs`,
 *                  `a.shortpixel-generateai`.
 *   - status:      optimized → text "Reduced by NN%" + `is-restorable`;
 *                  restored → cell back to unoptimized (NO text), the
 *                  "Item restored" message goes to the SIBLING
 *                  `#shortpixel-message-<id>`; API error → sibling gets
 *                  `.message.error`, cell may show `.shortpixel-image-error`
 *                  with a "click here to retry" link (`.shortpixel-error-reset a`).
 *   - no confirm() dialogs anywhere on this screen.
 *   - bulk bar:    SPIO options are injected into BOTH selects but only the
 *                  TOP Apply (`#doaction`) is wired; two options share
 *                  value "shortpixel-optimize" → select by LABEL. Items fire
 *                  1 per second (first after 1s) and uncheck themselves.
 *   - Screen Options column toggle: `#wp-shortPixel-hide` (checked = visible);
 *                  hiding is CSS-only, the cell stays in the DOM.
 */
import { expect, type Locator, type Page } from '@playwright/test';
import { adminUrls } from './spio';

/**
 * Option VALUES of the injected bulk actions (res/js/shortpixel.js:27-41).
 * Two options share "shortpixel-optimize" (header row + Optimize) — same
 * value, same behaviour, so selecting by value is unambiguous in effect.
 */
export const BulkOption = {
	optimize: 'shortpixel-optimize',
	lossy: 'shortpixel-lossy',
	glossy: 'shortpixel-glossy',
	lossless: 'shortpixel-lossless',
	restore: 'shortpixel-restore',
	markCompleted: 'shortpixel-mark-completed',
} as const;

/** The page must OWN SPIO's processor lock or the queue never advances. */
export async function expectProcessorActive(page: Page): Promise<void> {
	await expect
		.poll(() => page.evaluate(() => (window as any).ShortPixelProcessor?.isActive === true), {
			message: 'the page must be the active SPIO processor (stale bulk-secret lock?)',
			timeout: 15_000,
		})
		.toBe(true);
}

export class MediaList {
	constructor(readonly page: Page) {}

	async goto(query = ''): Promise<void> {
		await this.page.goto(adminUrls.mediaList + query);
		await expect(this.page.locator('table.wp-list-table.media')).toBeVisible();
		await expectProcessorActive(this.page);
	}

	cell(id: number): Locator {
		return this.page.locator(`#shortpixel-data-${id}`);
	}

	/** JS-created sibling that carries "Processing…", "Item restored", errors. */
	message(id: number): Locator {
		return this.page.locator(`#shortpixel-message-${id}`);
	}

	rowCheckbox(id: number): Locator {
		return this.page.locator(`#cb-select-${id}`);
	}

	/** Open the burger menu and return the dropdown content locator. */
	async openMenu(id: number): Promise<Locator> {
		const cell = this.cell(id);
		await cell.locator('.sp-dropbtn').click();
		await expect(cell.locator('.sp-dropdown')).toHaveClass(/\bsp-show\b/);
		return cell.locator('.sp-dropdown-content');
	}

	async expectOptimized(id: number, timeoutMs = 60_000): Promise<void> {
		const cell = this.cell(id);
		await expect(cell).toContainText(/Reduced by/i, { timeout: timeoutMs });
		await expect(cell).toHaveClass(/\bis-restorable\b/);
	}

	/** Unoptimized shape: the direct "Optimize Now" button is back. */
	async expectUnoptimized(id: number, timeoutMs = 60_000): Promise<void> {
		const cell = this.cell(id);
		await expect(cell.locator('a.optimize')).toBeVisible({ timeout: timeoutMs });
		await expect(cell).not.toHaveClass(/\bis-restorable\b/);
	}

	/** Pick a SPIO bulk action (by option value) in the TOP bar and apply it. */
	async applyBulkAction(value: string): Promise<void> {
		const select = this.page.locator('#bulk-action-selector-top');
		await expect(select.locator(`option[value="${value}"]`).first(), 'SPIO must have injected its bulk options').toBeAttached();
		await select.selectOption({ value });
		await this.page.locator('#doaction').click();
	}

	/** Screen Options → show/hide the "ShortPixel Compression" column. */
	async setColumnVisible(visible: boolean): Promise<void> {
		await this.page.locator('#show-settings-link').click();
		const box = this.page.locator('#wp-shortPixel-hide');
		await expect(box).toBeVisible();
		if (visible) {
			await box.check();
		} else {
			await box.uncheck();
		}
		await this.page.locator('#show-settings-link').click();
	}
}
