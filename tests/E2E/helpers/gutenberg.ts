/**
 * Block-editor helpers for the SPIO E2E suite.
 *
 * Facts (Wave 2 exploration):
 *   - the canvas is iframed (`iframe[name="editor-canvas"]`) on the block
 *     theme the E2E install uses; the inspector, the wp.media modal and
 *     everything SPIO renders live in the TOP document;
 *   - SPIO never generates AI data from the editor UI by itself. Selecting a
 *     core/image block only kicks the queue processor (ListenGutenberg);
 *     the generation is started from the Media Library (another tab), from
 *     the wp.media modal's "AI Image SEO" button, or directly via
 *     `ShortPixelProcessor.screen.RequestAlt(id)`;
 *   - results reach the open editor via HandleImage → UpdateGutenBerg
 *     (FILE_DONE + apiName 'ai'), which updates alt/caption/url on every
 *     top-level core/image block whose attributes.id matches;
 *   - the welcome guide is a per-user preference; disable it before load.
 */
import { expect, type Page } from '@playwright/test';
import { adminUrls } from './spio';

export type BlockAttributes = Record<string, unknown> & { id?: number; alt?: unknown; caption?: unknown; url?: string };

export class BlockEditor {
	constructor(readonly page: Page) {}

	/** Open the editor for a post with the welcome guide suppressed and the stores ready. */
	async open(postId: number): Promise<void> {
		await this.page.goto(adminUrls.editPost(postId));
		await this.page.waitForFunction(
			() =>
				!!(window as any).wp?.data?.select('core/editor')?.getCurrentPostId() &&
				(window as any).wp.data.select('core/block-editor').getBlocks().length > 0,
			null,
			{ timeout: 60_000 },
		);
		await this.dismissWelcomeGuide();
		await expect
			.poll(() => this.page.evaluate(() => !!(window as any).ShortPixelProcessor?.screen), {
				message: 'SPIO screen object must be loaded in the editor',
			})
			.toBe(true);
	}

	async dismissWelcomeGuide(): Promise<void> {
		await this.page.evaluate(() => {
			const prefs = (window as any).wp?.data?.dispatch('core/preferences');
			if (prefs) {
				prefs.set('core/edit-post', 'welcomeGuide', false);
				prefs.set('core', 'welcomeGuide', false);
			}
		});
		const close = this.page.locator('.components-modal__header button[aria-label="Close"]');
		if (await close.isVisible().catch(() => false)) {
			await close.click();
		}
	}

	async blocks(): Promise<Array<{ name: string; clientId: string; attributes: BlockAttributes }>> {
		return this.page.evaluate(() =>
			(window as any).wp.data
				.select('core/block-editor')
				.getBlocks()
				.map((b: any) => ({ name: b.name, clientId: b.clientId, attributes: b.attributes })),
		);
	}

	async imageBlock(attachmentId: number): Promise<BlockAttributes> {
		const blocks = await this.blocks();
		const block = blocks.find((b) => b.name === 'core/image' && Number(b.attributes.id) === attachmentId);
		expect(block, `a core/image block for attachment ${attachmentId} must exist`).toBeTruthy();
		return block!.attributes;
	}

	/** Select the block for an attachment (drives SPIO's ListenGutenberg). */
	async selectImageBlock(attachmentId: number): Promise<void> {
		await this.page.evaluate((id) => {
			const wp = (window as any).wp;
			const block = wp.data
				.select('core/block-editor')
				.getBlocks()
				.find((b: any) => b.name === 'core/image' && Number(b.attributes.id) === id);
			if (block) {
				wp.data.dispatch('core/block-editor').selectBlock(block.clientId);
			}
		}, attachmentId);
	}

	/** Serialize the current blocks — a corrupted image block shows up as a bare `/-->` void comment. */
	async serialize(): Promise<string> {
		return this.page.evaluate(() => (window as any).wp.blocks.serialize((window as any).wp.data.select('core/block-editor').getBlocks()));
	}

	/** Start AI generation for an attachment from inside the editor page (same call the UI buttons make). */
	async requestAlt(attachmentId: number): Promise<void> {
		await this.page.evaluate((id) => (window as any).ShortPixelProcessor.screen.RequestAlt(id), attachmentId);
	}

	/** Save the post through the editor's own store (no UI click needed). */
	async savePost(): Promise<void> {
		await this.page.evaluate(() => (window as any).wp.data.dispatch('core/editor').savePost());
		await this.page.waitForFunction(() => !(window as any).wp.data.select('core/editor').isSavingPost(), null, { timeout: 30_000 });
	}
}
