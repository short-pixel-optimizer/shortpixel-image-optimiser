/**
 * Logger debug box (build/shortpixel/log/src/view-debug-box.php).
 *
 * An administrator who adds `SHORTPIXEL_DEBUG` to an admin URL switches
 * logging on for that page load; the logger then prints a small draggable
 * box in the admin footer with a link to the log file. Support asks users
 * to open pages this way, so the box must render cleanly: the template is
 * included from `admin_footer`, enqueues jQuery UI draggable itself and
 * must not print PHP notices into the page.
 *
 * The console-error tripwire stays on: a script error in the box's inline
 * script (for example draggable not loaded) fails the test.
 */
import { test, expect } from '../fixtures';
import { adminUrls } from '../helpers/spio';

const BOX = '.sp_debug_box';

test.describe('Logger debug box', () => {
	test.beforeEach(async ({ spio }) => {
		await spio.reset();
	});

	test('SHORTPIXEL_DEBUG in the URL shows a draggable box', async ({ page }) => {
		await page.goto(adminUrls.mediaList + '&SHORTPIXEL_DEBUG=4');

		const box = page.locator(BOX);
		await expect(box).toHaveCount(1);
		await expect(box).toBeVisible();
		await expect(box.locator('.header')).toContainText('Debug Box');

		// The template printed nothing but the box: no PHP notice leaked into the footer.
		const footerText = await page.locator('#wpfooter, ' + BOX).allInnerTexts();
		expect(footerText.join('\n'), 'no PHP notice next to the box').not.toMatch(/(Warning|Notice|Deprecated|Fatal error):/);

		// SENTINEL: the template's own enqueue brought jQuery UI draggable in.
		expect(
			await page.evaluate(() => typeof (window as any).jQuery?.fn?.draggable),
			'sentinel: jQuery UI draggable is loaded',
		).toBe('function');
		await expect(box, 'the inline script made the box draggable').toHaveClass(/ui-draggable/);

		// Dragging the header moves the box.
		const before = await box.boundingBox();
		const header = await box.locator('.header').boundingBox();
		await page.mouse.move(header!.x + 10, header!.y + 10);
		await page.mouse.down();
		await page.mouse.move(header!.x - 190, header!.y + 90, { steps: 8 });
		await page.mouse.up();
		const after = await box.boundingBox();
		expect(Math.round(before!.x - after!.x), 'the box followed the mouse to the left').toBeGreaterThan(150);
	});

	/**
	 * The "Logfile" link of the box opens the plugin's log file
	 * (uploads/ShortpixelBackups/shortpixel_log). The logger builds the link
	 * when the box is printed, after ShortPixelPlugin::init() has set the
	 * log path. It is a plain link: browsers do not follow links to the
	 * `view-source:` scheme.
	 */
	test('the Logfile link of the debug box points at the log file', async ({ page }) => {
		await page.goto(adminUrls.mediaList + '&SHORTPIXEL_DEBUG=4');

		const link = page.locator(BOX).locator('a', { hasText: 'Logfile' });
		// SENTINEL: the box and its link are there.
		await expect(link).toHaveCount(1);

		const href = await link.getAttribute('href');
		expect(href, 'the link is a plain http(s) URL of the log file').toMatch(/^https?:\/\/.+\/ShortpixelBackups\/shortpixel_log$/);
		expect(href, 'no view-source: prefix').not.toContain('view-source:');
	});

	test('without SHORTPIXEL_DEBUG the box is not printed', async ({ page }) => {
		await page.goto(adminUrls.mediaList);
		await expect(page.locator('#wpfooter')).toBeAttached();
		await expect(page.locator(BOX)).toHaveCount(0);
	});
});
