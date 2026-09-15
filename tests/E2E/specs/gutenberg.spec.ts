/**
 * Wave 2 — Gutenberg AI alt/caption flow (Tier 2, where #66 lived).
 *
 * Covers, in a REAL open block editor:
 *   - regression #66 (a5ad9805): an AI generation started for the image
 *     while the post is open updates the core/image block's alt in place,
 *     keyed by the editor's post id;
 *   - block-corruption guard (ea764111): integer status codes (a field
 *     switched off in settings) never reach the block — serialization stays
 *     a real <figure>, never the bare void comment (`… /-->`);
 *   - the in-content replacement is also persisted in post_content;
 *   - the SPIO controls inside the editor's media modal render for the
 *     selected image ("AI Image SEO" snippet, AI editor launch buttons);
 *   - PIN: undo in the Media Library does not revert the open editor's block
 *     (UpdateGutenBerg call is commented out in the undo path).
 *
 * Mechanism note: SPIO never starts AI generation from the editor by itself;
 * the tests kick it with `ShortPixelProcessor.screen.RequestAlt(id)` — the
 * same call the "AI Image SEO by ShortPixel" button makes — and the editor
 * tab's own processor delivers the result (it owns the lock: cookies-only
 * session, reset() cleared the server half).
 */
import { test, expect } from '../fixtures';
import { BlockEditor } from '../helpers/gutenberg';
import { expectProcessorActive } from '../helpers/media-list';

test.describe('Gutenberg AI alt/caption', () => {
	test.beforeEach(async ({ spio }) => {
		await spio.reset();
		// Deterministic AI payload: alt + caption on, everything else off.
		await spio.setSettings({ enable_ai: 1, ai_gen_alt: 1, ai_gen_caption: 1, ai_gen_description: 0, ai_gen_post_title: 0, ai_gen_filename: 0 });
	});

	test('regression #66: AI alt generated while the post is open updates the image block in place', async ({ page, spio }) => {
		const image = await spio.uploadFixture('fixture-small.jpg');
		const post = await spio.createPost({ image_id: image.id, alt: '' });

		const editor = new BlockEditor(page);
		await editor.open(post.id);
		await expectProcessorActive(page);
		expect((await editor.imageBlock(image.id)).alt, 'precondition: empty alt').toBe('');

		await editor.selectImageBlock(image.id);
		await editor.requestAlt(image.id);

		// The result reaches the open editor via HandleImage → UpdateGutenBerg.
		await expect
			.poll(async () => (await editor.imageBlock(image.id)).alt, { timeout: 90_000, message: 'block alt must be updated live' })
			.toBe('A mock ai alt text.');
		const block = await editor.imageBlock(image.id);
		expect(typeof block.caption === 'string' ? block.caption : String(block.caption)).toMatch(/mock ai caption/i);

		// And the same replacement is in the stored post_content (server side).
		await expect.poll(async () => (await spio.getPost(post.id)).content, { timeout: 30_000 }).toContain('alt="A mock ai alt text."');

		// The block still serializes as a real image block (no corruption).
		expect(await editor.serialize()).toMatch(/<figure class="wp-block-image[^"]*"><img /);
		expect(await editor.serialize()).not.toMatch(/\/-->/);
	});

	test('corruption guard: an integer status for a switched-off field never reaches the block', async ({ page, spio }) => {
		// Caption generation OFF → the API payload carries an int status for
		// caption (-3 EXCLUDESETTING). Pre-ea764111 this reached
		// updateBlockAttributes and the block save() threw → void comment.
		await spio.setSettings({ ai_gen_caption: 0 });
		const image = await spio.uploadFixture('fixture-small.jpg');
		const post = await spio.createPost({ image_id: image.id, alt: '' });

		const editor = new BlockEditor(page);
		await editor.open(post.id);
		await expectProcessorActive(page);
		await editor.selectImageBlock(image.id);
		await editor.requestAlt(image.id);

		await expect.poll(async () => (await editor.imageBlock(image.id)).alt, { timeout: 90_000 }).toBe('A mock ai alt text.');
		const block = await editor.imageBlock(image.id);
		expect(typeof block.caption, 'caption must never become a number').not.toBe('number');

		const serialized = await editor.serialize();
		expect(serialized).toMatch(/<figure class="wp-block-image[^"]*"><img /);
		expect(serialized, 'no bare void image block comment').not.toMatch(/wp:image[^>]*\/-->/);

		// Saving from the editor keeps the post intact on the server too.
		await editor.savePost();
		const stored = (await spio.getPost(post.id)).content;
		expect(stored).toContain('<figure class="wp-block-image');
		expect(stored).toContain('alt="A mock ai alt text."');
	});

	test('the editor media modal shows the ShortPixel AI controls for the selected image', async ({ page, spio }) => {
		const image = await spio.uploadFixture('fixture-small.jpg');
		const post = await spio.createPost({ image_id: image.id, alt: '' });

		const editor = new BlockEditor(page);
		await editor.open(post.id);
		await editor.selectImageBlock(image.id);

		// Block toolbar → Replace → Open Media Library.
		await page.locator('.block-editor-block-toolbar button', { hasText: /^Replace$/ }).click();
		await page.getByRole('menuitem', { name: /Open Media Library/i }).click();
		const modal = page.locator('.media-modal');
		await expect(modal).toBeVisible();

		// The frame opens on "Upload files"; SPIO's row is part of the
		// attachment DETAILS view, which only renders once an attachment is
		// selected in the Media Library tab.
		await modal.getByRole('tab', { name: /Media Library/i }).click();
		const tile = modal.locator(`li.attachment[data-id="${image.id}"]`);
		await expect(tile).toBeVisible({ timeout: 30_000 });
		// A synthetic click highlights the tile but wp.media's selection stays
		// empty (probed: selection.length === 0), so no details view renders.
		// Select through the frame's own state, exactly what the click does.
		await page.evaluate((id) => {
			const frame = (window as any).wp.media.frame;
			const attachment = (window as any).wp.media.attachment(id);
			frame.state().get('selection').reset([attachment]);
		}, image.id);
		await expect(modal.locator('.attachment-info')).toBeVisible();

		// The selected attachment's details render SPIO's row + AI snippet.
		await expect(modal.locator('.attachment-info .shortpixel-popup-info')).toBeVisible({ timeout: 30_000 });
		await expect(modal.locator(`#shortpixel-ai-wrapper-${image.id}`)).toBeVisible({ timeout: 30_000 });
		await expect(modal.locator(`#shortpixel-ai-wrapper-${image.id} a.button`, { hasText: /AI Image SEO/i })).toBeVisible();

		// The AI editor launch buttons (Gutenberg opener: button-link style).
		await expect(modal.locator('#shortpixel_removebackground_button[data-opener="gutenberg"]')).toBeVisible();
		await expect(modal.locator('#shortpixel_scale_button[data-opener="gutenberg"]')).toBeVisible();
	});
});

test.describe('Gutenberg AI — pinned', () => {
	test.beforeEach(async ({ spio }) => {
		await spio.reset();
		await spio.setSettings({ enable_ai: 1, ai_gen_alt: 1, ai_gen_caption: 0, ai_gen_description: 0, ai_gen_post_title: 0, ai_gen_filename: 0 });
	});

	/**
	 * PIN (unnumbered — E2E seed finding): screen-item-base.js UndoAlt() has
	 * its UpdateGutenBerg() call commented out and re-broadcasts a
	 * `handleImage` whose payload carries apiName 'ai' but NO fileStatus, so
	 * HandleImage's FILE_DONE gate never fires. After an undo the server
	 * content is restored but the block in an open editor keeps the AI alt;
	 * the next editor save re-writes the AI text. Symmetric to #66's original
	 * failure, but on the undo side.
	 * FLIP-when-fixed: expect the block alt to return to '' after the undo.
	 */
	test('pin: undo does not revert the image block in an open editor (pinned_for_deferred_fix)', async ({ page, spio }) => {
		const image = await spio.uploadFixture('fixture-small.jpg');
		const post = await spio.createPost({ image_id: image.id, alt: '' });

		const editor = new BlockEditor(page);
		await editor.open(post.id);
		await expectProcessorActive(page);
		await editor.selectImageBlock(image.id);
		await editor.requestAlt(image.id);
		await expect.poll(async () => (await editor.imageBlock(image.id)).alt, { timeout: 90_000 }).toBe('A mock ai alt text.');

		// Undo from the same page (the snippet's Undo button calls this).
		await page.evaluate((id) => (window as any).ShortPixelProcessor.screen.UndoAlt(id, 'undo'), image.id);

		// SENTINEL: the server DID revert the content.
		await expect.poll(async () => (await spio.getPost(post.id)).content, { timeout: 30_000 }).not.toContain('A mock ai alt text.');
		await expect.poll(async () => (await spio.attachment(image.id)).alt, { timeout: 30_000 }).toBe('');

		// PINNED: the open editor's block still holds the AI alt.
		await page.waitForTimeout(3_000);
		expect((await editor.imageBlock(image.id)).alt, 'PIN: block alt not reverted in the open editor').toBe('A mock ai alt text.');
	});
});
