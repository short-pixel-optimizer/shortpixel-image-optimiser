/**
 * Page object for SPIO's AI image editor modal (background removal /
 * upscale), driven by res/js/screens/screen-media.js.
 *
 * Verified facts (Wave 2 exploration):
 *   - launch buttons are built by InitEditorActions(): `#shortpixel_removebackground_button`
 *     ("AI Background Removal") and `#shortpixel_scale_button` ("AI Image
 *     Upscale"), only for jpg/jpeg/png; the scale button is `disabled` (title
 *     "Image too big for scaling") when the image is wider than 1200px.
 *     Placement per opener: edit-media → `#media-head-<id> p > button`;
 *     grid modal → `.media-modal .attachment-actions`; Gutenberg sidebar →
 *     `.attachment-info` (scale first, dropped when disabled).
 *   - opening appends `#shortpixel-media-modal[data-action_name=remove|scale]`
 *     + `#shortpixel-media-modal-shade` to <body> BEFORE the popup HTML
 *     arrives (admin-ajax media/getEditorPopup), and injects
 *     `<link id="shortpixel-media-modal-css">` once per page (never removed).
 *   - popup (class/view/snippets/media-popup.php): `.modal-wrapper#media-modal`,
 *     close `span[data-action="close"]`, `.image-original > i`,
 *     `.image-preview > i` (preview = CSS background-image, cache-busted
 *     `?ts=`), `.load-preview-spinner` (hidden via `.shortpixel-hide`),
 *     `.error-message`, remove options `section.remove.action_wrapper`
 *     (radios name=background_type, `#solid_selector`, `#bg_display_picker`,
 *     `#bg_transparency`), scale options `section.scale.action_wrapper`
 *     (radios name=scale 2|3|4), `input[name="new_filename"]`,
 *     `input[name="new_posttitle"]`, `#media-get-preview`, `#media-save-button`.
 *   - popup_load_preview=true → a preview request fires automatically on open.
 *   - Save = same media/getEditorPreview with is_preview=false → the server
 *     downloads the result and media_handle_sideload()s it as a NEW
 *     attachment (source untouched), then: edit opener → redirect to the new
 *     attachment's edit screen; gallery → upload.php?item=<new>; gutenberg →
 *     adds it to wp.media's library and closes the modal.
 *   - getEditorPreview blocks server-side up to ~45s (15 × sleep(3)) while
 *     polling the API → generous timeouts.
 */
import { expect, type Locator, type Page } from '@playwright/test';

export type EditorAction = 'remove' | 'scale';

export class AiEditorModal {
	constructor(readonly page: Page) {}

	get modal(): Locator {
		return this.page.locator('#shortpixel-media-modal');
	}

	get shade(): Locator {
		return this.page.locator('#shortpixel-media-modal-shade');
	}

	get popup(): Locator {
		return this.modal.locator('.modal-wrapper');
	}

	launchButton(action: EditorAction): Locator {
		return this.page.locator(action === 'remove' ? '#shortpixel_removebackground_button' : '#shortpixel_scale_button');
	}

	/** Click a launch button and wait until the popup HTML has landed. */
	async open(action: EditorAction): Promise<void> {
		await this.launchButton(action).click();
		await expect(this.modal).toHaveAttribute('data-action_name', action);
		await expect(this.popup, 'popup HTML must arrive from media/getEditorPopup').toBeVisible({ timeout: 30_000 });
		await expect(this.modal.locator(`section.${action}.action_wrapper`)).toHaveClass(/\bactive\b/);
	}

	/** Computed-style probes proving the runtime-injected stylesheet applied. */
	async expectStyled(): Promise<void> {
		const probe = await this.page.evaluate(() => {
			const modal = document.getElementById('shortpixel-media-modal');
			const shade = document.getElementById('shortpixel-media-modal-shade');
			const link = document.getElementById('shortpixel-media-modal-css') as HTMLLinkElement | null;
			let rules = 0;
			try {
				rules = link?.sheet?.cssRules.length ?? 0;
			} catch {
				rules = -1;
			}
			return {
				modalPosition: modal ? getComputedStyle(modal).position : 'missing',
				shadePosition: shade ? getComputedStyle(shade).position : 'missing',
				rules,
			};
		});
		expect(probe.modalPosition, 'modal must be positioned by shortpixel-media-modal.css').toBe('fixed');
		expect(probe.shadePosition, 'shade must be positioned by shortpixel-media-modal.css').toBe('fixed');
		expect(probe.rules, 'the injected stylesheet must have parsed').toBeGreaterThan(0);
	}

	get spinner(): Locator {
		return this.modal.locator('.load-preview-spinner');
	}

	get errorMessage(): Locator {
		return this.modal.locator('.image-preview .error-message');
	}

	/** The preview lives in the CSS background-image of `.image-preview i`. */
	async previewBackground(): Promise<string> {
		return this.modal.locator('.image-preview i').evaluate((el) => getComputedStyle(el).backgroundImage);
	}

	/** Wait for a (non-placeholder) preview to land — the API answered. */
	async expectPreviewLoaded(timeoutMs = 90_000): Promise<string> {
		await expect
			.poll(() => this.previewBackground(), { timeout: timeoutMs, message: 'preview background-image must point at the API result' })
			.toMatch(/api\.shortpixel\.com\/f\/.*\?ts=/);
		await expect(this.spinner).toHaveClass(/\bshortpixel-hide\b/);
		return this.previewBackground();
	}

	async clickPreview(): Promise<void> {
		await this.modal.locator('#media-get-preview').click();
	}

	async setScale(factor: '2' | '3' | '4'): Promise<void> {
		await this.modal.locator(`input[name="scale"][value="${factor}"]`).evaluate((el) => {
			(el as HTMLInputElement).checked = true;
			el.dispatchEvent(new Event('change', { bubbles: true }));
		});
	}

	async setBackground(type: 'transparent' | 'solid'): Promise<void> {
		await this.modal.locator(`input[name="background_type"][value="${type}"]`).evaluate((el) => {
			(el as HTMLInputElement).checked = true;
			el.dispatchEvent(new Event('change', { bubbles: true }));
		});
	}

	get newFilename(): Locator {
		return this.modal.locator('input[name="new_filename"]');
	}

	get saveButton(): Locator {
		return this.modal.locator('#media-save-button');
	}

	async close(): Promise<void> {
		await this.modal.locator('[data-action="close"]').click();
		await expect(this.modal).toHaveCount(0);
		await expect(this.shade).toHaveCount(0);
	}
}
