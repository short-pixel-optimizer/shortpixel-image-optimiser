/**
 * Gutenberg AI alt/caption flow.
 *
 * Covers, in a REAL open block editor:
 *   - an AI generation started for the image while the post is open
 *     updates the core/image block's alt in place, keyed by the editor's
 *     post id;
 *   - block-corruption guard: integer status codes (a field
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

	test('AI alt generated while the post is open updates the image block in place', async ({ page, spio }) => {
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
		// caption (-3 EXCLUDESETTING). If it reached updateBlockAttributes,
		// the block save() would throw → void comment.
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
	 * PIN: screen-item-base.js UndoAlt() has
	 * its UpdateGutenBerg() call commented out and re-broadcasts a
	 * `handleImage` whose payload carries apiName 'ai' but NO fileStatus, so
	 * HandleImage's FILE_DONE gate never fires. After an undo the server
	 * content is restored but the block in an open editor keeps the AI alt;
	 * the next editor save re-writes the AI text.
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

	/**
	 * REGRESSION — an AI rename that writes NO alt into the open post must
	 * still move the block to the new URL.
	 *
	 * UpdateGutenBerg (screen-media.js) must not return early on a
	 * rename-only result (post alt already filled in 'missing' mode, 'none'
	 * mode, alt off…): otherwise the server renames the file and rewrites
	 * post_content, the editor keeps the old URL and the next save writes it
	 * back (broken image). Lives in the "pinned" describe because it shares
	 * its setup; it is a regression test.
	 */
	test('regression: an AI rename without an alt write moves the block to the new URL', async ({ page, spio }) => {
		await spio.setSettings({ enable_ai: 1, ai_gen_alt: 1, ai_gen_caption: 0, ai_gen_filename: 1, ai_content_replace: 'missing' });
		const newBase = 'gb-renamed-' + Date.now();
		await spio.setMock({ aiFields: { generated_file_name: newBase } });

		const image = await spio.uploadFixture('fixture-small.jpg');
		// An alt already in the post → 'missing' mode writes no alt → rename-only result.
		// A DRAFT: a published post would count as "image in use", and the AI
		// rename (not a fresh upload) then keeps the old name.
		const post = await spio.createPost({ image_id: image.id, alt: 'Existing alt', status: 'draft' });

		const editor = new BlockEditor(page);
		await editor.open(post.id);
		await expectProcessorActive(page);
		const oldUrl = String((await editor.imageBlock(image.id)).url);
		expect(oldUrl, 'precondition: the block starts on the old file').not.toContain(newBase);

		// SENTINEL: the rename result (carrying replaced_url) really reaches this page.
		const delivered = page.waitForResponse(
			async (r) => r.url().includes('admin-ajax.php') && (await r.text().catch(() => '')).includes('replaced_url'),
			{ timeout: 90_000 }
		);
		await editor.selectImageBlock(image.id);
		await editor.requestAlt(image.id);
		await delivered;

		// SENTINEL: the server renamed the file and rewrote the stored content.
		await expect.poll(async () => (await spio.attachment(image.id)).attached_file, { timeout: 30_000 }).toContain(newBase);
		await expect.poll(async () => (await spio.getPost(post.id)).content, { timeout: 30_000 }).toContain(newBase);

		// The open editor's block follows the rename (thumbnail suffix kept).
		await expect
			.poll(async () => String((await editor.imageBlock(image.id)).url), {
				timeout: 30_000,
				message: 'REGRESSION: the block url must follow a rename-only result',
			})
			.toContain(newBase);
		expect(String((await editor.imageBlock(image.id)).url)).not.toBe(oldUrl);
	});

	/**
	 * An AI rename also updates the attachment the editor's media library
	 * holds in memory (wp.media.attachment): its url and every size url move
	 * to the new file name, each size keeping its "-WxH" suffix. Without this
	 * the upload / media modal keeps offering the old, now missing, URLs
	 * until the page is reloaded.
	 */
	test('an AI rename updates the attachment URLs of the editor media library', async ({ page, spio }) => {
		await spio.setSettings({ enable_ai: 1, ai_gen_alt: 1, ai_gen_caption: 0, ai_gen_filename: 1, ai_content_replace: 'missing' });
		const newBase = 'gb-modal-' + Date.now();
		await spio.setMock({ aiFields: { generated_file_name: newBase } });

		const image = await spio.uploadFixture('fixture-small.jpg');
		const post = await spio.createPost({ image_id: image.id, alt: 'Existing alt', status: 'draft' });

		const editor = new BlockEditor(page);
		await editor.open(post.id);
		await expectProcessorActive(page);

		// Load the attachment into the media library's model, as opening the modal does.
		const before = await page.evaluate(async (id) => {
			const attachment = (window as any).wp.media.attachment(id);
			await attachment.fetch();
			const sizes = attachment.get('sizes') || {};
			return { url: String(attachment.get('url')), sizes: Object.fromEntries(Object.keys(sizes).map((k) => [k, String(sizes[k].url)])) };
		}, image.id);
		// SENTINELS: the model is loaded, on the old name, with at least one thumbnail size.
		expect(before.url, 'sentinel: the model starts on the old file').not.toContain(newBase);
		const thumbSizes = Object.keys(before.sizes).filter((k) => /-\d+x\d+\.[a-z]+$/i.test(before.sizes[k]));
		expect(thumbSizes.length, 'sentinel: the model holds thumbnail sizes').toBeGreaterThan(0);

		const delivered = page.waitForResponse(
			async (r) => r.url().includes('admin-ajax.php') && (await r.text().catch(() => '')).includes('replaced_url'),
			{ timeout: 90_000 }
		);
		await editor.selectImageBlock(image.id);
		await editor.requestAlt(image.id);
		await delivered;
		await expect.poll(async () => (await spio.attachment(image.id)).attached_file, { timeout: 30_000 }).toContain(newBase);

		const readModel = () =>
			page.evaluate((id) => {
				const attachment = (window as any).wp.media.attachment(id);
				const sizes = attachment.get('sizes') || {};
				return { url: String(attachment.get('url')), sizes: Object.fromEntries(Object.keys(sizes).map((k) => [k, String(sizes[k].url)])) };
			}, image.id);
		await expect.poll(async () => (await readModel()).url, { timeout: 30_000, message: 'the model url must follow the rename' }).toContain(newBase);

		const after = await readModel();
		for (const name of thumbSizes) {
			const suffix = before.sizes[name].match(/(-\d+x\d+)\.[a-z]+$/i)![1];
			expect(after.sizes[name], `size "${name}" moves to the new name`).toContain(newBase + suffix);
		}
	});

	/**
	 * PIN — for a big image (WordPress serves "name-scaled.jpg") the rename
	 * moves the media library's url and "full" size to "newname.jpg", without
	 * "-scaled": UpdateGutenBerg() (screen-media.js) only keeps a "-WxH"
	 * suffix. That URL exists, but it is the unscaled original upload, so
	 * inserting the image at full size before a reload embeds the original.
	 * @todo In screen-media.js UpdateGutenBerg(): keep "-scaled" as well, e.g.
	 *       `/(-\d+x\d+|-scaled)(\.[^.]+)$/` for the suffix match.
	 * FLIP-when-fixed: the url ends in newBase + "-scaled.jpg".
	 */
	test('pin: an AI rename of a big image drops "-scaled" from the media library url (pinned_for_deferred_fix)', async ({ page, spio }) => {
		await spio.setSettings({ enable_ai: 1, ai_gen_alt: 1, ai_gen_caption: 0, ai_gen_filename: 1, ai_content_replace: 'missing' });
		const newBase = 'gb-big-' + Date.now();
		await spio.setMock({ aiFields: { generated_file_name: newBase } });

		const image = await spio.uploadFixture('fixture-large.jpg');
		const post = await spio.createPost({ image_id: image.id, alt: 'Existing alt', status: 'draft' });

		const editor = new BlockEditor(page);
		await editor.open(post.id);
		await expectProcessorActive(page);

		const modelUrl = () => page.evaluate((id) => String((window as any).wp.media.attachment(id).get('url')), image.id);
		await page.evaluate(async (id) => { await (window as any).wp.media.attachment(id).fetch(); }, image.id);
		// SENTINEL: WordPress serves the scaled copy of this upload.
		expect(await modelUrl(), 'sentinel: the model starts on the -scaled file').toMatch(/-scaled\.jpg$/);

		const delivered = page.waitForResponse(
			async (r) => r.url().includes('admin-ajax.php') && (await r.text().catch(() => '')).includes('replaced_url'),
			{ timeout: 120_000 }
		);
		await editor.selectImageBlock(image.id);
		await editor.requestAlt(image.id);
		await delivered;

		// SENTINELS: the file on disk keeps "-scaled", and the model followed the rename.
		await expect.poll(async () => (await spio.attachment(image.id)).attached_file, { timeout: 60_000 }).toContain(newBase + '-scaled.jpg');
		await expect.poll(modelUrl, { timeout: 30_000 }).toContain(newBase);

		expect(await modelUrl(), 'PIN: fixed? the model url keeps "-scaled" — flip this pin').toMatch(new RegExp(newBase + '\\.jpg$'));
	});
});

/**
 * Regressions in UpdateGutenBerg() (res/js/screens/screen-media.js), which
 * pushes the AI result into the open editor.
 */
test.describe('Gutenberg AI — nested blocks and unsaved posts (regressions)', () => {
	test.beforeEach(async ({ spio }) => {
		await spio.reset();
		await spio.setSettings({ enable_ai: 1, ai_gen_alt: 1, ai_gen_caption: 0, ai_gen_description: 0, ai_gen_post_title: 0, ai_gen_filename: 0, ai_content_replace: 'missing' });
	});

	/**
	 * REGRESSION ("saving a post erases the alt for images inside a Group or
	 * Columns block") — UpdateGutenBerg() walks getClientIdsWithDescendants(),
	 * not only the TOP-LEVEL blocks; otherwise an image nested in a
	 * Group/Columns block is never updated in the editor and the next Save
	 * writes its empty alt back over the AI alt the server stored.
	 */
	test('an image inside a Group block gets the AI alt in the editor and keeps it on save', async ({ page, spio }) => {
		const image = await spio.uploadFixture('fixture-small.jpg');
		const content =
			'<!-- wp:group {"layout":{"type":"constrained"}} -->\n<div class="wp-block-group">' +
			`<!-- wp:image {"id":${image.id},"sizeSlug":"full","linkDestination":"none"} -->\n` +
			`<figure class="wp-block-image size-full"><img src="${image.url}" alt="" class="wp-image-${image.id}"/></figure>\n` +
			'<!-- /wp:image --></div>\n<!-- /wp:group -->';
		const post = await spio.createPost({ content });

		const editor = new BlockEditor(page);
		await editor.open(post.id);
		await expectProcessorActive(page);
		expect((await editor.imageBlockDeep(image.id)).alt, 'precondition: empty alt').toBe('');

		await editor.selectImageBlockDeep(image.id);
		await editor.requestAlt(image.id);

		// SENTINEL: the server really wrote the AI alt into the saved post.
		await expect.poll(async () => (await spio.getPost(post.id)).content, { timeout: 90_000 }).toContain('alt="A mock ai alt text."');

		// The nested block in the open editor gets the AI alt…
		await expect
			.poll(async () => (await editor.imageBlockDeep(image.id)).alt, { timeout: 30_000, message: 'REGRESSION: the nested image block shows the AI alt' })
			.toBe('A mock ai alt text.');

		// …and saving from the editor keeps it.
		await editor.savePost();
		await expect
			.poll(async () => (await spio.getPost(post.id)).content, { timeout: 30_000, message: 'REGRESSION: Save keeps the AI alt' })
			.toContain('alt="A mock ai alt text."');
	});

	/**
	 * REGRESSION ("no alt text for an image added to a post that hasn't been
	 * saved yet") — an unsaved (auto-draft) post has no stored content for the
	 * server to write into, so replaced_content has no entry for it;
	 * UpdateGutenBerg() falls back to the generated aiData and fills empty
	 * alt/caption in the block.
	 */
	test('an image in a never-saved post gets the AI alt', async ({ page, spio }) => {
		const image = await spio.uploadFixture('fixture-small.jpg');

		await page.goto('/wp-admin/post-new.php');
		await page.waitForFunction(() => !!(window as any).wp?.data?.select('core/editor')?.getCurrentPostId(), null, { timeout: 60_000 });
		const editor = new BlockEditor(page);
		await editor.dismissWelcomeGuide();
		await expect.poll(() => page.evaluate(() => !!(window as any).ShortPixelProcessor?.screen)).toBe(true);
		await expectProcessorActive(page);

		// Insert an Image block for the uploaded attachment; do NOT save.
		await page.evaluate(({ id, url }) => {
			const wp = (window as any).wp;
			const block = wp.blocks.createBlock('core/image', { id, url, alt: '', sizeSlug: 'full' });
			wp.data.dispatch('core/block-editor').insertBlocks(block);
			wp.data.dispatch('core/block-editor').selectBlock(block.clientId);
		}, { id: image.id, url: image.url });
		const status = await page.evaluate(() => (window as any).wp.data.select('core/editor').getEditedPostAttribute('status'));
		expect(status, 'Sentinel: the post has never been saved').toBe('auto-draft');

		await editor.requestAlt(image.id);

		// SENTINEL: the AI alt was generated for the image (Media Library).
		await expect.poll(async () => (await spio.attachment(image.id)).alt, { timeout: 90_000 }).toBe('A mock ai alt text.');

		await expect
			.poll(async () => (await editor.imageBlockDeep(image.id)).alt, { timeout: 30_000, message: 'REGRESSION: the unsaved post\'s block gets the AI alt' })
			.toBe('A mock ai alt text.');
	});
});
