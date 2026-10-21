/**
 * Edit-media screen: what an AI result does to the attachment form fields.
 *
 * Mechanism under test (res/js/screens/screen-media.js FetchAltView()):
 * when an AI result arrives, the screen copies alt, caption and description
 * into the edit form. A field that was NOT generated arrives as an INTEGER
 * status instead of text (-3: switched off in settings, -4: kept by
 * aiPreserve). Such a field must be left alone — writing '' over it blanks
 * what the user has, and the next Update saves the blank.
 *
 *   - alt: numbers are skipped (regression test);
 *   - caption / description: still blanked (pinned).
 *
 * The AI request is started with `ShortPixelProcessor.screen.RequestAlt(id)`,
 * the call the "AI Image SEO" button makes. FetchAltView is wrapped so the
 * tests wait for the real result instead of a timeout, and can check that
 * the integer statuses really arrived.
 */
import { test, expect } from '../fixtures';
import type { Page } from '@playwright/test';
import { adminUrls } from '../helpers/spio';
import { expectProcessorActive } from '../helpers/media-list';

const USER_ALT = 'Hand-written alt';
const USER_CAPTION = 'Hand-written caption';
const USER_DESCRIPTION = 'Hand-written description';

/** Opens the attachment's edit screen, fills the form and runs AI on it. */
async function runAiWithFilledForm(page: Page, id: number): Promise<Record<string, unknown>> {
	await page.goto(adminUrls.editAttachment(id));
	await expect(page.locator(`#media-head-${id}`)).toBeVisible();
	await expectProcessorActive(page);
	// The screen tags the alt field with the item id once its AI interface loaded.
	await expect
		.poll(() => page.evaluate(() => (document.getElementById('attachment_alt') as HTMLElement | null)?.dataset.shortpixelAlt), {
			message: 'the AI interface must attach to the edit form',
			timeout: 30_000,
		})
		.toBe(String(id));

	await page.evaluate(
		({ alt, caption, description }) => {
			(document.getElementById('attachment_alt') as HTMLInputElement).value = alt;
			(document.getElementById('attachment_caption') as HTMLTextAreaElement).value = caption;
			(document.getElementById('attachment_content') as HTMLTextAreaElement).value = description;

			const screen = (window as any).ShortPixelProcessor.screen;
			const original = screen.FetchAltView.bind(screen);
			(window as any).__spioAiResults = [];
			screen.FetchAltView = (aiData: unknown, itemId: unknown) => {
				const result = original(aiData, itemId);
				if (aiData) (window as any).__spioAiResults.push(aiData);
				return result;
			};
		},
		{ alt: USER_ALT, caption: USER_CAPTION, description: USER_DESCRIPTION },
	);

	await page.evaluate((itemId) => (window as any).ShortPixelProcessor.screen.RequestAlt(itemId), id);
	await expect
		.poll(() => page.evaluate(() => (window as any).__spioAiResults.length), {
			message: 'the AI result must reach the edit screen',
			timeout: 90_000,
		})
		.toBeGreaterThan(0);

	return page.evaluate(() => (window as any).__spioAiResults[0]);
}

const fieldValue = (page: Page, elementId: string) =>
	page.evaluate((el) => (document.getElementById(el) as HTMLInputElement).value, elementId);

test.describe('Edit media — AI result and fields that were not generated', () => {
	test.beforeEach(async ({ spio }) => {
		await spio.reset();
		// Only the title is generated; alt, caption and description come back as -3.
		await spio.setSettings({ enable_ai: 1, ai_gen_alt: 0, ai_gen_caption: 0, ai_gen_description: 0, ai_gen_post_title: 1, ai_gen_filename: 0 });
	});

	test('an alt that was not generated leaves the alt field alone', async ({ page, spio }) => {
		const { id } = await spio.uploadFixture('fixture-small.jpg');
		const aiData = await runAiWithFilledForm(page, id);

		expect(typeof aiData.alt, 'pre-condition: the alt arrives as an integer status').toBe('number');
		expect(await fieldValue(page, 'attachment_alt')).toBe(USER_ALT);
	});

	/**
	 * @todo res/js/screens/screen-media.js FetchAltView(): skip a numeric
	 *       caption / description the way the alt branch does, instead of
	 *       turning it into ''.
	 * FLIP-when-fixed: both fields keep the user's text. Drop the suffix.
	 */
	test('a caption and description that were not generated keep the user\'s text (pinned_for_deferred_fix)', async ({ page, spio }) => {
		const { id } = await spio.uploadFixture('fixture-small.jpg');
		const aiData = await runAiWithFilledForm(page, id);

		expect(typeof aiData.caption, 'pre-condition: the caption arrives as an integer status').toBe('number');
		expect(typeof aiData.description, 'pre-condition: the description arrives as an integer status').toBe('number');
		expect(await fieldValue(page, 'attachment_caption'), 'caption field blanked by an integer status').toBe(USER_CAPTION);
		expect(await fieldValue(page, 'attachment_content'), 'description field blanked by an integer status').toBe(USER_DESCRIPTION);
	});
});
