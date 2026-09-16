/**
 * Front-end delivery helpers (what site VISITORS get).
 *
 * Facts (Wave 3 exploration):
 *   - SPIO ships no visitor JS; delivery is server-side output rewriting.
 *   - Mode selection (FrontController:32-43): `useCDN` wins outright; else
 *     `deliverWebp` 1 = <picture> via whole-page output buffer, 2 = via WP
 *     content filters (the_content @10000 …), 3 = unaltered (.htaccess
 *     rules only), 0 = nothing. CDN and <picture> are mutually exclusive.
 *   - The webp/avif files must physically EXIST next to each size on disk:
 *     `createWebp`/`createAvif` only control generation at optimize time.
 *   - Rewritten image: `<picture><source type="image/avif"><source type="image/webp"><img … class="… sp-no-webp"></picture>`;
 *     `img.sp-no-webp` is the "SPIO ran" marker; images without companions
 *     are returned byte-identical.
 *   - Not checked anywhere: is_user_logged_in (the admin bar does not
 *     suppress delivery). Excluded: admin, ajax, REST, cron, feeds, 404, AMP.
 *   - CDN mode rewrites src/srcset in place to
 *     `https://<cdn>/spio/<args '+'-joined>/<host><path>`; enabling it via
 *     the settings FORM makes unmocked outbound calls (no-cdn.shortpixel.ai)
 *     — set it through the support route instead; a dummy CDN host fails
 *     DNS in the browser, so route-fulfill it.
 */
import { expect, type Locator, type Page } from '@playwright/test';
import { NO_KEEPALIVE_HEADERS } from './spio';

export const DeliverWebp = {
	OFF: 0,
	PICTURE_GLOBAL: 1, // output buffer (NOT covered by PHPUnit — E2E-only)
	PICTURE_WP_HOOKS: 2,
	UNALTERED_HTACCESS: 3,
} as const;

/** Every image candidate URL on the page: <img src>, <img srcset>, <source srcset>. */
export async function collectImageUrls(scope: Locator): Promise<string[]> {
	return scope.evaluate((root: Element) => {
		const urls = new Set<string>();
		const split = (srcset: string | null) =>
			(srcset || '')
				.split(',')
				.map((c) => c.trim().split(/\s+/)[0])
				.filter(Boolean);
		root.querySelectorAll('img').forEach((img) => {
			if (img.getAttribute('src')) urls.add(img.getAttribute('src')!);
			split(img.getAttribute('srcset')).forEach((u) => urls.add(u));
		});
		root.querySelectorAll('source').forEach((s) => split(s.getAttribute('srcset')).forEach((u) => urls.add(u)));
		return [...urls];
	});
}

/** Fetch every URL through Playwright's request context and assert 200 + an image content type. */
export async function expectAllImagesLoad(page: Page, urls: string[]): Promise<void> {
	expect(urls.length, 'there must be image URLs to check').toBeGreaterThan(0);
	for (const url of urls) {
		const response = await page.request.get(url, { headers: NO_KEEPALIVE_HEADERS });
		expect(response.status(), `${url} must load`).toBe(200);
		expect(response.headers()['content-type'] || '', `${url} must be an image`).toMatch(/^image\//);
	}
}

/** The browser's own choice for an <img> (after <picture> negotiation). */
export async function currentSrc(img: Locator): Promise<string> {
	return img.evaluate((el) => (el as HTMLImageElement).currentSrc);
}

/** Wait until the browser has actually finished loading an <img>. */
export async function expectImageLoaded(img: Locator): Promise<void> {
	await expect
		.poll(() => img.evaluate((el) => (el as HTMLImageElement).complete && (el as HTMLImageElement).naturalWidth > 0), {
			message: 'image must have loaded with a non-zero natural width',
		})
		.toBe(true);
}
