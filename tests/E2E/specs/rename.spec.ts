/**
 * Manual "Change Filename" on the attachment edit screen (#submitdiv).
 *
 * #77 (fixed in 8b625159 + 11aa2065, 2026-09-25): the rename used to reload
 * the page unconditionally, so a failed rename looked like a success. The
 * response now carries the result in media.results[0] and the
 * 'ShortPixelMedia.reloadWindow' listener (screen-media.js) reloads only when
 * is_error is false; otherwise it appends the message under the field.
 *
 * Failure is forced without any mock: renaming image A to image B's existing
 * name trips replaceFiles()'s target-conflict guard ("Files were not
 * replaced"), a real, deterministic failure.
 */
import { test, expect } from '../fixtures';
import type { Page } from '@playwright/test';
import { adminUrls } from '../helpers/spio';

// Same WebKit engine limitation as ai-editor.spec.ts: WebKit hangs in layout
// on the WP attachment edit screen (sentinel in engine-limits.spec.ts).
test.skip(({ browserName }) => browserName === 'webkit', 'WebKit layout hang on the WP attachment edit screen (see engine-limits.spec.ts)');

const FIELD = '#submitdiv .misc-pub-filename.shortpixel-replace-if input[name="filename_replace"]';
const BUTTON = '#submitdiv .misc-pub-filename.shortpixel-replace-if button[name="filename_replace_submit"]';
const ERROR = '#submitdiv .misc-pub-filename p.error';

function baseOf(relpath: string): string {
	return relpath.split('/').pop()!.replace(/\.[^.]+$/, '');
}

async function openRenameField(page: Page, id: number): Promise<void> {
	await page.goto(adminUrls.editAttachment(id));
	await expect(page.locator(`#media-head-${id}`)).toBeVisible();
	// SPIO swaps the core "File name" row for its rename interface once the
	// AI view has loaded.
	await expect(page.locator(FIELD)).toBeVisible({ timeout: 30_000 });
}

/** Mark the current document; a reload drops the mark. */
async function markDocument(page: Page): Promise<void> {
	await page.evaluate(() => ((window as any).__spioNoReload = true));
}

async function documentWasKept(page: Page): Promise<boolean> {
	return page.evaluate(() => (window as any).__spioNoReload === true);
}

/** Resolves when the replaceFileName AJAX round-trip has been answered. */
function renameAnswered(page: Page) {
	return page.waitForResponse(
		(r) => r.url().includes('admin-ajax.php') && (r.request().postData() || '').includes('replaceFileName'),
		{ timeout: 30_000 }
	);
}

test.describe('Change Filename (edit-media)', () => {
	test.beforeEach(async ({ spio }) => {
		await spio.reset();
	});

	test('regression #77: a failed rename shows the error and does NOT reload', async ({ page, spio }) => {
		const a = await spio.uploadFixture('fixture-small.jpg');
		const b = await spio.uploadFixture('fixture-small.jpg');
		const aBefore = await spio.attachment(a.id);
		const bBase = baseOf((await spio.attachment(b.id)).attached_file);
		expect(bBase, 'Sentinel: the two uploads must have different names').not.toBe(baseOf(aBefore.attached_file));

		await openRenameField(page, a.id);
		await markDocument(page);
		await page.locator(FIELD).fill(bBase);
		const answered = renameAnswered(page);
		await page.locator(BUTTON).click();
		await answered;

		await expect(page.locator(ERROR)).toHaveText(/Files were not replaced/);
		expect(await documentWasKept(page), 'REGRESSION #77: a failed rename must not reload the page').toBe(true);
		expect((await spio.attachment(a.id)).attached_file, 'Nothing may be renamed on a conflict').toBe(aBefore.attached_file);
	});

	test('a successful rename reloads the page with the new filename', async ({ page, spio }) => {
		const a = await spio.uploadFixture('fixture-small.jpg');
		const newBase = 'e2e-renamed-' + Date.now();

		await openRenameField(page, a.id);
		await markDocument(page);
		await page.locator(FIELD).fill(newBase);
		const reloaded = page.waitForEvent('load', { timeout: 30_000 });
		await page.locator(BUTTON).click();
		await reloaded;

		expect(await documentWasKept(page), 'A successful rename reloads the page').toBe(false);
		expect((await spio.attachment(a.id)).attached_file).toContain(newBase);
		await expect(page.locator(FIELD)).toHaveValue(new RegExp(newBase));
	});

});

/**
 * PIN (unnumbered, found 2026-09-25) — a too-short name throws instead of
 * showing the rejection.
 *
 * AjaxController::replaceFileName() still answers its input-validation
 * rejections (missing / empty / < 3 characters after sanitising) with the
 * OLD flat object ({error, is_error, message}); the callback is echoed, so
 * the #77 'ShortPixelMedia.reloadWindow' listener runs and reads
 * `event.detail.media.results[0]` — `media` does not exist on that shape, so
 * it throws (screen-media.js, AttachAiInterface). The user sees nothing: no
 * message, no reload, and the field keeps the rejected name.
 *
 * Suggested fix: answer the rejections in the same media.results[0] shape
 * (is_error=true + the specific 'error' text as message), or guard the
 * listener (`data.media && data.media.results`).
 *
 * FLIP-when-fixed: drop `allowConsoleErrors`, assert the ERROR text is
 * visible, and let the tripwire guard it.
 */
test.describe('Change Filename — too-short name pin', () => {
	test.use({ allowConsoleErrors: true });

	test.beforeEach(async ({ spio }) => {
		await spio.reset();
	});

	test('pin: a too-short filename throws in the reload listener instead of showing the rejection (pinned_for_deferred_fix)', async ({ page, spio }) => {
		const a = await spio.uploadFixture('fixture-small.jpg');
		const before = (await spio.attachment(a.id)).attached_file;

		await openRenameField(page, a.id);
		const pageErrors: string[] = [];
		page.on('pageerror', (e) => pageErrors.push(String(e)));
		await markDocument(page);
		await page.locator(FIELD).fill('ab');
		const answered = renameAnswered(page);
		await page.locator(BUTTON).click();
		const response = await answered;

		// SENTINEL: the server really rejected it (REGRESSION #50 contract),
		// in the old flat shape.
		const body = await response.json();
		expect(body.is_error, 'Sentinel: the server must reject a 2-character name').toBe(true);
		expect(body.media, 'Sentinel: the rejection is the flat shape without media.results').toBeUndefined();
		expect((await spio.attachment(a.id)).attached_file).toBe(before);

		// THE PIN: the listener throws on the flat shape.
		await expect
			.poll(() => pageErrors.length, {
				timeout: 10_000,
				message: 'PIN: fixed? No uncaught error on a too-short name — flip this pin.',
			})
			.toBeGreaterThan(0);
		// Engine wording differs (Firefox names the property, Chromium does not).
		expect(pageErrors.join('\n')).toMatch(/data\.media is undefined|Cannot read properties of undefined \(reading 'results'\)/);
		await expect(page.locator(ERROR), 'PIN: no message is shown to the user').toHaveCount(0);
		expect(await documentWasKept(page)).toBe(true);
	});
});
