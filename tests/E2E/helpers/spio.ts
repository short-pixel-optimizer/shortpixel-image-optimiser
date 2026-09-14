/**
 * Client for the spio-e2e/v1 test-support REST API (see
 * tests/E2E/mu-plugins/spio-e2e-support.php) plus SPIO-specific page
 * helpers (CustomEvent waits, admin URLs).
 */
import { expect, type APIRequestContext, type Page } from '@playwright/test';

export type MockKnobs = Partial<{
	forceStatusCode: number | null;
	malformedBody: string | null;
	wpErrorMessage: string | null;
	waitingRounds: number;
	aiAddStatus: number | null;
	aiWaitingRounds: number;
	aiFields: Record<string, string>;
}>;

export type MockRequest = { time: string; url: string; path: string; request: unknown };

export type AttachmentStatus = {
	id: number;
	optimized: boolean | null;
	attached_file: string;
	alt: string;
	file: string | null;
};

export class SpioSupport {
	private readonly token = process.env.E2E_TOKEN || 'spio-e2e-local';

	constructor(private readonly request: APIRequestContext, private readonly baseURL: string) {}

	private url(route: string): string {
		// ?rest_route= works regardless of the permalink structure.
		return `${this.baseURL}/?rest_route=/spio-e2e/v1/${route}`;
	}

	private async post<T = unknown>(route: string, data?: unknown): Promise<T> {
		const response = await this.request.post(this.url(route), {
			data: data ?? {},
			headers: { 'X-SPIO-E2E-TOKEN': this.token },
		});
		expect(response.ok(), `support endpoint ${route}: HTTP ${response.status()} ${await response.text()}`).toBe(true);
		return (await response.json()) as T;
	}

	private async get<T = unknown>(route: string): Promise<T> {
		const response = await this.request.get(this.url(route), {
			headers: { 'X-SPIO-E2E-TOKEN': this.token },
		});
		expect(response.ok(), `support endpoint ${route}: HTTP ${response.status()} ${await response.text()}`).toBe(true);
		return (await response.json()) as T;
	}

	/** Wipe content + SPIO tables + mock state + hostile snippets, then re-seed. */
	reset(): Promise<{ ok: boolean }> {
		return this.post('reset');
	}

	/** Re-apply the healthy-install baseline without wiping content. */
	seed(): Promise<{ ok: boolean }> {
		return this.post('seed');
	}

	/** Set SPIO settings (wpSPIO()->settings()->key = value). */
	setSettings(settings: Record<string, unknown>): Promise<{ ok: boolean }> {
		return this.post('settings', settings);
	}

	/** update_option(name, value). */
	setOption(name: string, value: unknown): Promise<{ ok: boolean }> {
		return this.post('option', { name, value });
	}

	/** Upload tests/fixtures/<name> as a real attachment. */
	uploadFixture(name: string): Promise<{ id: number; url: string; file: string }> {
		return this.post('fixture', { name });
	}

	/** Server-side truth about one attachment. */
	attachment(id: number): Promise<AttachmentStatus> {
		return this.get(`attachment/${id}`);
	}

	/** Age every queue row past ShortQ's process_timeout. */
	backdateQueue(): Promise<{ ok: boolean; rows: number }> {
		return this.post('queue/backdate');
	}

	/** Merge knobs into the mock ShortPixel API. */
	setMock(knobs: MockKnobs): Promise<{ ok: boolean; knobs: MockKnobs }> {
		return this.post('mock', knobs);
	}

	resetMock(): Promise<{ ok: boolean }> {
		return this.post('mock/reset');
	}

	/** The mock's log of intercepted *.shortpixel.com requests. */
	mockRequests(): Promise<MockRequest[]> {
		return this.get('mock/requests');
	}

	/** Enable hostile third-party snippets (names from mu-plugins/hostile-snippets/). */
	setHostileSnippets(snippets: string[]): Promise<{ ok: boolean }> {
		return this.post('hostile', { snippets });
	}
}

/** wp-admin URLs of the SPIO screens (relative to baseURL). */
export const adminUrls = {
	dashboard: '/wp-admin/',
	settings: '/wp-admin/options-general.php?page=wp-shortpixel-settings',
	bulk: '/wp-admin/upload.php?page=wp-short-pixel-bulk',
	customMedia: '/wp-admin/upload.php?page=wp-short-pixel-custom',
	mediaList: '/wp-admin/upload.php?mode=list',
	mediaGrid: '/wp-admin/upload.php?mode=grid',
};

/**
 * Run `action` and wait for SPIO to dispatch the named window CustomEvent
 * (e.g. 'shortpixel.processor.responseHandled'). The listener is installed
 * BEFORE the action runs so the event cannot be missed — the anti-flake
 * replacement for arbitrary sleeps.
 */
export async function withSpioEvent<T>(
	page: Page,
	eventName: string,
	action: () => Promise<T>,
	timeoutMs = 30_000,
): Promise<T> {
	const waiter = page.evaluate(
		([name, timeout]) =>
			new Promise<boolean>((resolve, reject) => {
				const timer = setTimeout(() => reject(new Error(`timed out waiting for window event "${name}"`)), timeout);
				window.addEventListener(
					name,
					() => {
						clearTimeout(timer);
						resolve(true);
					},
					{ once: true },
				);
			}),
		[eventName, timeoutMs] as const,
	);
	const result = await action();
	await waiter;
	return result;
}

/** Cheap layout sanity: the document never scrolls horizontally. */
export async function expectNoHorizontalOverflow(page: Page): Promise<void> {
	const overflow = await page.evaluate(() => ({
		scrollWidth: document.documentElement.scrollWidth,
		clientWidth: document.documentElement.clientWidth,
	}));
	expect(overflow.scrollWidth, `horizontal overflow: scrollWidth ${overflow.scrollWidth} > clientWidth ${overflow.clientWidth}`)
		.toBeLessThanOrEqual(overflow.clientWidth + 1);
}
