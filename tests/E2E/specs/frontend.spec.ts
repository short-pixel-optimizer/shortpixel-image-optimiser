/**
 * Wave 3 — front-end delivery (what site visitors get).
 *
 * SPIO ships no visitor JS; everything here is server-side output
 * rewriting, so the E2E value is precisely what PHPUnit cannot check: the
 * markup the BROWSER parsed, whether every candidate URL really loads with
 * an image content type, which source the browser actually chose
 * (currentSrc), and that the layout is unchanged against a delivery-off
 * baseline. Mode 1 (whole-page output buffer) is deliberately not covered
 * by the PHPUnit suite at all (test-FrontendDelivery.php header) — it is
 * exercised here for the first time.
 *
 * Setup per test: createWebp/createAvif ON → optimize the image through the
 * real pipeline (the mock produces real webp/avif bytes) → set deliverWebp
 * → view the post as a visitor (a fresh, unauthenticated context).
 */
import { test, expect } from '../fixtures';
import { MediaList } from '../helpers/media-list';
import { SettingsPage } from '../helpers/settings-page';
import { DeliverWebp, collectImageUrls, currentSrc, expectAllImagesLoad, expectImageLoaded } from '../helpers/frontend';
import { NO_KEEPALIVE_HEADERS, expectNoHorizontalOverflow, setChecked } from '../helpers/spio';

/** Optimize one uploaded image through the Media Library (so the companions exist on disk). */
async function optimizeViaMediaList(page: import('@playwright/test').Page, id: number): Promise<void> {
	const list = new MediaList(page);
	await list.goto();
	await list.cell(id).locator('a.optimize').click();
	await list.expectOptimized(id);
}

test.describe('Front-end delivery', () => {
	test.beforeEach(async ({ spio }) => {
		await spio.reset();
		await spio.setSettings({ createWebp: 1, createAvif: 1, deliverWebp: DeliverWebp.OFF, useCDN: 0 });
	});

	test.afterEach(async ({ spio }) => {
		// Never leak delivery/CDN into the other specs.
		await spio.setSettings({ deliverWebp: DeliverWebp.OFF, useCDN: 0, createWebp: 0, createAvif: 0 });
	});

	for (const [label, mode] of [
		['mode 1 — whole-page output buffer', DeliverWebp.PICTURE_GLOBAL],
		['mode 2 — WP content filters', DeliverWebp.PICTURE_WP_HOOKS],
	] as const) {
		test(`${label}: the image block becomes a <picture> whose sources all load and the browser picks the webp/avif`, async ({
			page,
			spio,
			browser,
		}) => {
			const image = await spio.uploadFixture('fixture-large.jpg'); // 3200×2400 → -scaled + several sizes
			const post = await spio.createPost({ image_id: image.id, alt: 'a landscape' });
			await optimizeViaMediaList(page, image.id);
			await spio.setSettings({ deliverWebp: mode });

			// A real visitor: fresh unauthenticated context.
			const visitor = await browser.newContext();
			const front = await visitor.newPage();
			const errors: string[] = [];
			front.on('pageerror', (e) => errors.push(`pageerror: ${e.message}`));
			front.on('console', (m) => m.type() === 'error' && errors.push(`console.error: ${m.text()}`));
			await front.goto(post.url);

			const figure = front.locator('figure.wp-block-image');
			const picture = figure.locator('picture');
			await expect(picture, 'the block must be wrapped in <picture>').toHaveCount(1);
			const img = picture.locator('img.sp-no-webp');
			await expect(img, 'the rebuilt <img> carries the sp-no-webp marker').toHaveCount(1);
			await expect(img).toHaveAttribute('alt', 'a landscape');
			await expect(img).toHaveClass(new RegExp(`wp-image-${image.id}`));

			// DOM order: avif source, then webp source, then img.
			const webp = picture.locator('source[type="image/webp"]');
			const avif = picture.locator('source[type="image/avif"]');
			await expect(webp).toHaveCount(1);
			await expect(avif).toHaveCount(1);
			const order = await picture.evaluate((p) => [...p.children].map((c) => c.tagName.toLowerCase() + (c.getAttribute('type') || '')));
			expect(order).toEqual(['sourceimage/avif', 'sourceimage/webp', 'img']);

			// Every candidate inside the webp/avif sources really is that format
			// (the known fallback-to-jpg wart would show up here).
			for (const [source, ext] of [[webp, 'webp'], [avif, 'avif']] as const) {
				const candidates = (await source.getAttribute('srcset'))!.split(',').map((c) => c.trim().split(/\s+/)[0]);
				expect(candidates.length).toBeGreaterThan(1);
				for (const url of candidates) {
					expect(url, `${ext} source candidate`).toMatch(new RegExp(`\\.${ext}$`));
				}
			}

			// The browser resolved to a next-gen format, and every URL loads.
			await expectImageLoaded(img);
			expect(await currentSrc(img)).toMatch(/\.(avif|webp)($|\?)/);
			await expectAllImagesLoad(front, await collectImageUrls(picture));

			await expectNoHorizontalOverflow(front);
			expect(errors, 'no JS/console errors on the visitor page').toEqual([]);
			await visitor.close();
		});
	}

	test('delivery off: the same post renders a plain <img> and every URL still loads (baseline)', async ({ page, spio, browser }) => {
		const image = await spio.uploadFixture('fixture-large.jpg');
		const post = await spio.createPost({ image_id: image.id, alt: '' });
		await optimizeViaMediaList(page, image.id);
		// deliverWebp stays OFF.

		const visitor = await browser.newContext();
		const front = await visitor.newPage();
		await front.goto(post.url);
		const figure = front.locator('figure.wp-block-image');
		await expect(figure.locator('picture')).toHaveCount(0);
		const img = figure.locator('img');
		await expect(img).toHaveCount(1);
		await expect(img).not.toHaveClass(/sp-no-webp/);
		await expectImageLoaded(img);
		expect(await currentSrc(img)).toMatch(/\.jpg($|\?)/);
		await expectAllImagesLoad(front, await collectImageUrls(figure));
		await visitor.close();
	});

	test('mode 1: an image without companions is left byte-identical (no <picture>)', async ({ page, spio, browser }) => {
		// Uploaded but NOT optimized → no webp/avif on disk.
		const image = await spio.uploadFixture('fixture-small.jpg');
		const post = await spio.createPost({ image_id: image.id, alt: '' });
		await spio.setSettings({ deliverWebp: DeliverWebp.PICTURE_GLOBAL });

		const visitor = await browser.newContext();
		const front = await visitor.newPage();
		await front.goto(post.url);
		const figure = front.locator('figure.wp-block-image');
		await expect(figure.locator('picture')).toHaveCount(0);
		await expect(figure.locator('img')).not.toHaveClass(/sp-no-webp/);
		await expectImageLoaded(figure.locator('img'));
		await visitor.close();
		void page;
	});

	test('mode 1: the layout is unchanged by the rewrite (same rendered size as the baseline)', async ({ page, spio, browser }) => {
		const image = await spio.uploadFixture('fixture-large.jpg');
		const post = await spio.createPost({ image_id: image.id, alt: '' });
		await optimizeViaMediaList(page, image.id);

		const measure = async () => {
			const visitor = await browser.newContext({ viewport: { width: 1366, height: 768 } });
			const front = await visitor.newPage();
			await front.goto(post.url);
			const img = front.locator('figure.wp-block-image img');
			await expectImageLoaded(img);
			const box = await img.boundingBox();
			const pageHeight = await front.evaluate(() => document.documentElement.scrollHeight);
			await visitor.close();
			return { box, pageHeight };
		};

		const baseline = await measure();
		await spio.setSettings({ deliverWebp: DeliverWebp.PICTURE_GLOBAL });
		const withPicture = await measure();

		expect(withPicture.box, 'image still rendered').not.toBeNull();
		expect(Math.round(withPicture.box!.width)).toBe(Math.round(baseline.box!.width));
		expect(Math.round(withPicture.box!.height)).toBe(Math.round(baseline.box!.height));
		expect(Math.round(withPicture.box!.y)).toBe(Math.round(baseline.box!.y));
		expect(withPicture.pageHeight).toBe(baseline.pageHeight);
	});

	test('mode 3 (unaltered): markup untouched, Apache serves the webp via the .htaccess rules', async ({ page, spio, browser }) => {
		const image = await spio.uploadFixture('fixture-small.jpg');
		const post = await spio.createPost({ image_id: image.id, alt: '' });
		await optimizeViaMediaList(page, image.id);
		// The .htaccess rules are written on the SETTINGS SAVE path
		// (processWebP → alterHtaccess), not by setting the value directly.
		const settingsPage = new SettingsPage(page);
		await settingsPage.goto('webp');
		await settingsPage.setSwitch('deliverWebp', true);
		await setChecked(page.locator('#deliverWebpUnaltered'), true);
		await settingsPage.save('webp');
		expect(Number((await spio.getSettings()).deliverWebp)).toBe(DeliverWebp.UNALTERED_HTACCESS);

		const visitor = await browser.newContext();
		const front = await visitor.newPage();
		await front.goto(post.url);
		const figure = front.locator('figure.wp-block-image');
		await expect(figure.locator('picture')).toHaveCount(0);
		const img = figure.locator('img');
		await expect(img).not.toHaveClass(/sp-no-webp/);
		await expectImageLoaded(img);

		// Chromium sends Accept: image/webp and a Chrome UA → the rewrite
		// rules serve the .webp bytes under the .jpg URL.
		const jpgUrl = await img.getAttribute('src');
		const response = await front.request.get(jpgUrl!, { headers: { ...NO_KEEPALIVE_HEADERS, Accept: 'image/webp,image/*,*/*' } });
		expect(response.status()).toBe(200);
		expect(response.headers()['content-type'], 'Apache must transparently serve the webp').toBe('image/webp');
		await visitor.close();
	});
});
