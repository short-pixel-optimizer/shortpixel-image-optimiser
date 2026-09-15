/**
 * Wave 2 — AI image editor modal (background removal / upscale).
 *
 * The runtime-CSS-injected modal is the flakiest layout code in the plugin
 * (exploration, 2026-09-15). These tests exercise the edit-media opener end
 * to end against the mock API: launch buttons (incl. the width-based disable
 * and the jpg/png gate), open → styled → auto-preview, option wiring,
 * Preview + Save creating a NEW attachment and redirecting to it, error
 * display when the API rejects, and close/cleanup — plus two PINS for
 * defects the exploration surfaced.
 *
 * Note: the mock answers background-removal/upscale with the unchanged
 * bytes (it does not process pixels), so assertions are about the FLOW
 * (requests, DOM, new attachment), never about image content.
 */
import { test, expect } from '../fixtures';
import { AiEditorModal } from '../helpers/ai-editor';
import { adminUrls, ApiCode } from '../helpers/spio';

async function openEditScreen(page: import('@playwright/test').Page, id: number): Promise<void> {
	await page.goto(adminUrls.editAttachment(id));
	await expect(page.locator(`#media-head-${id}`)).toBeVisible();
}

test.describe('AI editor modal — edit-media opener', () => {
	test.beforeEach(async ({ spio }) => {
		await spio.reset();
	});

	test('launch buttons render for a jpg and the upscale button honours the 1200px cap', async ({ page, spio }) => {
		// fixture-small.jpg is 1200×900 → exactly at the cap → upscale allowed.
		const small = (await spio.uploadFixture('fixture-small.jpg')).id;
		await openEditScreen(page, small);
		const modal = new AiEditorModal(page);
		await expect(modal.launchButton('remove')).toBeVisible();
		await expect(modal.launchButton('remove')).toHaveText(/AI Background Removal/);
		await expect(modal.launchButton('scale')).toBeVisible();
		await expect(modal.launchButton('scale')).toHaveText(/AI Image Upscale/);
		await expect(modal.launchButton('scale')).toBeEnabled();

		// fixture-large.jpg is 3200 wide (2560 -scaled) → too big to upscale.
		const large = (await spio.uploadFixture('fixture-large.jpg')).id;
		await openEditScreen(page, large);
		await expect(modal.launchButton('scale')).toBeDisabled();
		await expect(modal.launchButton('scale')).toHaveAttribute('title', /too big/i);
	});

	test('no launch buttons for a non-jpg/png image (gif)', async ({ page, spio }) => {
		const gif = (await spio.uploadFixture('fixture-large.gif')).id;
		await openEditScreen(page, gif);
		const modal = new AiEditorModal(page);
		await expect(modal.launchButton('remove')).toHaveCount(0);
		await expect(modal.launchButton('scale')).toHaveCount(0);
	});

	test('background removal: opens styled, auto-loads a preview, Save creates a new attachment', async ({ page, spio }) => {
		const id = (await spio.uploadFixture('fixture-small.png')).id;
		await openEditScreen(page, id);
		const modal = new AiEditorModal(page);

		await modal.open('remove');
		await modal.expectStyled();
		await expect(modal.modal.locator('#transparent_background')).toBeChecked();
		await expect(modal.newFilename).toHaveValue(/_nobg\.png$/);

		// popup_load_preview=true → the preview request fires by itself.
		await modal.expectPreviewLoaded();
		const requests = await spio.mockRequests();
		const reducer = requests.filter((r) => r.path.includes('reducer') && (r.request as any)?.bg_remove !== undefined);
		expect(reducer.length, 'the API must have been asked for a background removal').toBeGreaterThan(0);

		// Solid colour option reveals the colour picker block.
		await modal.setBackground('solid');
		await expect(modal.modal.locator('#solid_selector')).toBeVisible();
		await modal.setBackground('transparent');

		// Save → new attachment, redirect to ITS edit screen; source untouched.
		// Save = dump (cleanup.php) + reducer + download + sideload, polled
		// server-side every 3s — comfortably longer than the default nav timeout.
		await Promise.all([
			page.waitForURL((url) => url.searchParams.get('post') !== String(id) && /action=edit/.test(url.href), {
				waitUntil: 'load',
				timeout: 120_000,
			}),
			modal.saveButton.click(),
		]);
		const newId = Number(new URL(page.url()).searchParams.get('post'));
		expect(newId, 'redirected to a NEW attachment').not.toBe(id);
		expect(newId).toBeGreaterThan(id);
		const created = await spio.attachment(newId);
		expect(created.attached_file).toMatch(/_nobg\.png$/);
		expect((await spio.attachment(id)).attached_file, 'the source attachment is untouched').not.toMatch(/_nobg/);
	});

	test('upscale: scale factor is sent to the API and Save creates the upscaled attachment', async ({ page, spio }) => {
		const id = (await spio.uploadFixture('fixture-small.jpg')).id;
		await openEditScreen(page, id);
		const modal = new AiEditorModal(page);

		await modal.open('scale');
		await modal.expectStyled();
		await expect(modal.newFilename).toHaveValue(/_upscale\.jpg$/);
		await modal.expectPreviewLoaded();

		// fixture-small is 1200 wide: 2x (cap 1200) is allowed, 3x/4x are not.
		await expect(modal.modal.locator('input[name="scale"][value="2"]')).toBeChecked();

		await modal.clickPreview();
		await modal.expectPreviewLoaded();
		const upscales = (await spio.mockRequests()).filter((r) => r.path.includes('reducer') && (r.request as any)?.upscale !== undefined);
		expect(upscales.length).toBeGreaterThan(0);
		expect(Number((upscales[0].request as any).upscale)).toBe(2);

		// Save = dump (cleanup.php) + reducer + download + sideload, polled
		// server-side every 3s — comfortably longer than the default nav timeout.
		await Promise.all([
			page.waitForURL((url) => url.searchParams.get('post') !== String(id) && /action=edit/.test(url.href), {
				waitUntil: 'load',
				timeout: 120_000,
			}),
			modal.saveButton.click(),
		]);
		const newId = Number(new URL(page.url()).searchParams.get('post'));
		expect(newId).not.toBe(id);
		expect((await spio.attachment(newId)).attached_file).toMatch(/_upscale\.jpg$/);
	});

	test('an API error is shown in the modal and nothing is created', async ({ page, spio }) => {
		const id = (await spio.uploadFixture('fixture-small.jpg')).id;
		await spio.setMock({ forceStatusCode: ApiCode.INVALID_URL });
		await openEditScreen(page, id);
		const modal = new AiEditorModal(page);

		await modal.open('remove');
		await expect(modal.errorMessage).not.toHaveClass(/\bshortpixel-hide\b/, { timeout: 90_000 });
		await expect(modal.errorMessage).not.toBeEmpty();
		await expect(modal.spinner).toHaveClass(/\bshortpixel-hide\b/);
		// The preview stays on the placeholder.
		expect(await modal.previewBackground()).toMatch(/placeholder/);

		await modal.close();
		// No new attachment sneaked in.
		await page.goto(adminUrls.mediaList);
		await expect(page.locator('#shortpixel-data-' + (id + 1))).toHaveCount(0);
	});

	test('closing removes the modal and shade; the injected stylesheet stays (by design, once per page)', async ({ page, spio }) => {
		const id = (await spio.uploadFixture('fixture-small.jpg')).id;
		await openEditScreen(page, id);
		const modal = new AiEditorModal(page);

		await modal.open('scale');
		await modal.close();
		await expect(page.locator('#shortpixel-media-modal-css')).toHaveCount(1);

		// Re-open on the same page: no second <link>, popup works again.
		await modal.open('remove');
		await expect(page.locator('#shortpixel-media-modal-css')).toHaveCount(1);
		await modal.expectStyled();
		await modal.close();
	});
});

test.describe('AI editor modal — pinned defects', () => {
	test.beforeEach(async ({ spio }) => {
		await spio.reset();
	});

	/**
	 * PIN (unnumbered — E2E seed finding): media-popup.php renders the Save
	 * button with `type='button button-primary'` (an invalid type value), so
	 * the modal's block-buttons toggle — `querySelectorAll('button[type="button"]')`
	 * — never matches it. Save stays clickable while a preview/save request
	 * is in flight; a double click starts two runs (two API calls, and on
	 * save two new attachments).
	 * FLIP-when-fixed (type='button' + class): expect Save to be disabled
	 * while the spinner is visible.
	 */
	test('pin: Save is not blocked while a preview request is running (pinned_for_deferred_fix)', async ({ page, spio }) => {
		const id = (await spio.uploadFixture('fixture-small.jpg')).id;
		await spio.setMock({ waitingRounds: 4 }); // keep the request in flight
		await openEditScreen(page, id);
		const modal = new AiEditorModal(page);

		await modal.open('scale');
		// SENTINEL: a request IS in flight (spinner shown) and the other
		// type="button" control (Preview) IS blocked by the same toggle.
		await expect(modal.spinner).not.toHaveClass(/\bshortpixel-hide\b/);
		await expect(modal.modal.locator('#media-get-preview')).toBeDisabled();

		// PINNED: Save is still enabled.
		await expect(modal.saveButton, 'PIN: Save button has an invalid type attribute and is never disabled').toBeEnabled();
		expect(await modal.saveButton.getAttribute('type')).toBe('button button-primary');

		await spio.setMock({ waitingRounds: 0 });
		await modal.expectPreviewLoaded();
		await modal.close();
	});
});
