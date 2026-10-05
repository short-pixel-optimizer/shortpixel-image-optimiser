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

	test('a warning above the button links to the knowledge base, and the whole rename area has a tooltip', async ({ page, spio }) => {
		const a = await spio.uploadFixture('fixture-small.jpg');
		await openRenameField(page, a.id);
		const row = page.locator('#submitdiv .misc-pub-filename.shortpixel-replace-if');
		const notice = row.locator('.shortpixel-rename-notice');
		await expect(notice).toBeVisible();
		await expect(notice).toContainText('Warning: Please read before renaming.');
		const warning = notice.locator('.shortpixel-rename-warning');
		await expect(warning).toHaveText('Warning:');
		await expect(warning, '"Warning:" uses the same red as "Delete permanently"').toHaveCSS('color', 'rgb(179, 45, 46)');
		await expect(warning, '"Warning:" is not bold (the red is enough)').toHaveCSS('font-weight', '400');
		const help = notice.locator('a.shortpixel-help-link');
		await expect(help).toHaveAttribute('target', '_blank');
		await expect(help).toHaveAttribute('href', /shortpixel\.com\/knowledge-base\/.*utm_campaign=plugin_media_library/);

		// One tooltip covers the field, the warning and the button.
		const area = row.locator('.shortpixel-rename-area');
		await expect(area).toHaveAttribute('title', 'Renaming this file may break links to this image. External links and Google Images results using the old URL will no longer work. No redirect is created from the old filename.');
		await expect(area.locator('input[name="filename_replace"]')).toHaveCount(1);
		await expect(area.locator('.shortpixel-rename-notice')).toHaveCount(1);
		await expect(area.locator('button[name="filename_replace_submit"]')).toHaveText('Rename file');
		// The field has no title of its own that would hide the area's tooltip.
		await expect(area.locator('input[name="filename_replace"]')).not.toHaveAttribute('title', /.+/);
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
		// Red left border, and only one result message at a time.
		await expect(page.locator(ERROR)).toHaveClass(/shortpixel-rename-result/);
		await expect(page.locator(ERROR)).toHaveCSS('border-left-color', 'rgb(255, 0, 0)');
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

		// Confirmation with a green left border (same box style as the settings warnings).
		const ok = page.locator('#submitdiv .misc-pub-filename .shortpixel-rename-result.is-success');
		await expect(ok).toHaveText('File successfully renamed!');
		await expect(ok).toHaveCSS('border-left-color', 'rgb(0, 200, 152)');
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

/** Rename through the UI and wait for the page reload a success triggers. */
async function renameAndReload(page: Page, value: string): Promise<void> {
	await markDocument(page);
	await page.locator(FIELD).fill(value);
	const reloaded = page.waitForEvent('load', { timeout: 30_000 });
	await page.locator(BUTTON).click();
	await reloaded;
	expect(await documentWasKept(page), 'A successful rename reloads the page').toBe(false);
}

/**
 * "-scaled" in filenames (Pedro, 2026-09-28).
 *
 * Two WordPress core rules shape these names:
 *   - wp_unique_filename() ALWAYS appends "-1" to an upload whose name ends
 *     in -scaled / -rotated / -WxH: "photo-scaled.jpg" is stored as
 *     "photo-scaled-1.jpg".
 *   - An image above big_image_size_threshold (2560px) keeps the upload as
 *     metadata['original_image'] and SERVES "<original>-scaled.jpg". SPIO's
 *     Change Filename field shows the ORIGINAL name, so on such an image the
 *     "-scaled" is not part of the editable name at all.
 */
test.describe('Change Filename — "-scaled" names', () => {
	test.beforeEach(async ({ spio }) => {
		await spio.reset();
	});

	for (const fixture of ['fixture-large.jpg', 'fixture-small.jpg']) {
		test(`an upload named "…-scaled" (stored as "…-scaled-1") can drop the "-scaled" — ${fixture}`, async ({ page, spio }) => {
			const stem = 'e2e-' + Date.now().toString(36) + '-photo';
			const up = await spio.uploadFixture(fixture, `${stem}-scaled.jpg`);
			const big = fixture === 'fixture-large.jpg';

			// SENTINELS — WordPress core naming, before SPIO does anything.
			const before = await spio.attachment(up.id);
			expect(baseOf(before.attached_file), 'Sentinel: WP appended "-1" (and "-scaled" for a big image)').toBe(
				big ? `${stem}-scaled-1-scaled` : `${stem}-scaled-1`
			);
			await openRenameField(page, up.id);
			await expect(page.locator(FIELD), 'Sentinel: the field shows the ORIGINAL name').toHaveValue(`${stem}-scaled-1.jpg`);

			await renameAndReload(page, `${stem}-1`);

			const after = await spio.attachment(up.id);
			expect(baseOf(after.attached_file)).toBe(big ? `${stem}-1-scaled` : `${stem}-1`);
			await expect(page.locator(FIELD)).toHaveValue(`${stem}-1.jpg`);
			const served = await page.request.get(`/wp-content/uploads/${after.attached_file}`);
			expect(served.status(), 'The renamed file is served').toBe(200);
		});
	}

	/**
	 * CONTRACT — Pedro's observation: on a BIG image the "-scaled" of the
	 * served file cannot be removed. The field already shows the original name
	 * without it, so "removing -scaled" means submitting the current name, and
	 * the only answer is the generic "Files were not replaced" (the same
	 * message as a real failure — worth a clearer text, see the UI copy review).
	 * Typing "<name>-scaled" is refused too: it would collide with the file
	 * WordPress serves.
	 */
	test('on a big image the served "-scaled" is not editable: unchanged or "-scaled" names are refused, nothing moves', async ({ page, spio }) => {
		const stem = 'e2e-' + Date.now().toString(36) + '-big';
		const up = await spio.uploadFixture('fixture-large.jpg', `${stem}.jpg`);
		const before = await spio.attachment(up.id);
		expect(baseOf(before.attached_file), 'Sentinel: WP serves the big-image "-scaled" copy').toBe(`${stem}-scaled`);

		for (const typed of [stem, `${stem}-scaled`]) {
			await openRenameField(page, up.id);
			await expect(page.locator(FIELD), 'The field shows the name WITHOUT "-scaled"').toHaveValue(`${stem}.jpg`);
			await markDocument(page);
			await page.locator(FIELD).fill(typed);
			const answered = renameAnswered(page);
			await page.locator(BUTTON).click();
			await answered;
			await expect(page.locator(ERROR)).toHaveText(/Files were not replaced/);
			expect(await documentWasKept(page)).toBe(true);
			expect((await spio.attachment(up.id)).attached_file, `Nothing moves for "${typed}"`).toBe(before.attached_file);
		}
	});
});

test.describe('Change Filename — "-scaled" regression81', () => {
	test.beforeEach(async ({ spio }) => {
		await spio.reset();
	});

	/**
	 * REGRESSION #81 (found in 3fd40001, fixed in dfa346be) — the UI side of
	 * test_regression81_stripping_a_dimension_suffix_… (test-ChangeFilename.php).
	 * A name ENDING in "-scaled" can only come from an earlier rename (a typed
	 * name skips wp_unique_filename) or a pre-WP-5.3 upload. Stripping the
	 * suffix again used to report success while WordPress kept pointing at the
	 * old name, whose file was moved away (the image 404'd). No console errors
	 * are allowed: the reloaded edit screen must not request a missing image.
	 */
	test('regression81: stripping a typed "-scaled" moves WordPress to the stripped name', async ({ page, spio }) => {
		const stem = 'e2e-' + Date.now().toString(36) + '-typed';
		const up = await spio.uploadFixture('fixture-small.jpg', `${stem}.jpg`);

		await openRenameField(page, up.id);
		await renameAndReload(page, `${stem}-scaled`);
		const mid = await spio.attachment(up.id);
		expect(baseOf(mid.attached_file), 'Sentinel: the typed "-scaled" name was applied').toBe(`${stem}-scaled`);

		await renameAndReload(page, stem);

		const after = await spio.attachment(up.id);
		expect(baseOf(after.attached_file), 'REGRESSION #81: the attachment follows the rename').toBe(stem);
		const served = await page.request.get(`/wp-content/uploads/${after.attached_file}`);
		expect(served.status(), 'REGRESSION #81: the image WordPress points at exists').toBe(200);
	});
});
