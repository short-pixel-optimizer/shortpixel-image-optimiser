/**
 * Shared test fixtures for the SPIO E2E suite. Every spec imports `test`
 * and `expect` from here instead of from @playwright/test so that:
 *
 *   1. The CONSOLE-ERROR TRIPWIRE is armed on every page of every test —
 *      any uncaught JS exception (`pageerror`) or console.error fails the
 *      test at teardown. Most of the JS bugs found in the SPIO survey are
 *      silent uncaught TypeErrors (a feature just goes dead), so this single
 *      mechanism turns every flow test into a crash detector for everything
 *      it touches. A spec that legitimately expects errors (e.g. a pin for a
 *      known bug) opts out with `test.use({ allowConsoleErrors: true })` and
 *      asserts on `consoleErrors` explicitly.
 *
 *   2. The run is HERMETIC: requests to spcdn.shortpixel.ai (the async
 *      chatbot widget), shortpixel.com (inline-help iframes), gravatar.com
 *      (avatars: the admin bar, the theme) and s.w.org (WordPress's emoji
 *      images, fetched by engines without an emoji font — Linux WebKit) are
 *      aborted at the browser — uncontrolled third-party layout/console
 *      noise, and a DNS hiccup on any of them would otherwise trip the
 *      console-error tripwire in whichever test happens to be running. API
 *      traffic never reaches the browser at all (it is mocked server-side
 *      by the spio-e2e-mock-api mu-plugin).
 *
 *   3. `spio` gives specs the test-support REST client (reset/seed/fixtures/
 *      mock knobs/hostile snippets) — see helpers/spio.ts.
 */
import { test as base, expect, type Browser, type BrowserContext, type BrowserContextOptions, type Page } from '@playwright/test';
import { SpioSupport } from './helpers/spio';

type Fixtures = {
	/** Set to true (via test.use) for specs that assert on expected JS errors. */
	allowConsoleErrors: boolean;
	/** Collected `pageerror` + console.error entries for the current test. */
	consoleErrors: string[];
	/** Test-support REST client (resets state, uploads fixtures, steers the mock). */
	spio: SpioSupport;
};

/** Hosts whose requests are aborted in the browser to keep runs hermetic. */
const BLOCKED_HOSTS = /(^|\.)spcdn\.shortpixel\.ai$|(^|\.)shortpixel\.com$|(^|\.)gravatar\.com$|^s\.w\.org$/i;

/**
 * Console errors that are noise, not signal. Keep this list SHORT and
 * documented — every entry is a place a real bug could hide.
 */
const CONSOLE_ALLOWLIST: RegExp[] = [
	// Our own hermetic blocking above surfaces as a failed resource load.
	/net::ERR_BLOCKED_BY_CLIENT/,
	// WordPress admin has no favicon in a stock install.
	/favicon\.ico/,
];

/** Aborts every request to BLOCKED_HOSTS made by any page of `context`. */
async function blockThirdPartyHosts(context: BrowserContext): Promise<void> {
	await context.route(
		(url) => BLOCKED_HOSTS.test(url.hostname),
		(route) => route.abort('blockedbyclient'),
	);
}

/**
 * A fresh, unauthenticated context (a site visitor) with the same hermetic
 * routing as the fixture's own context. Use it instead of
 * browser.newContext(): a context created that way bypasses the routing, so
 * the theme's third-party requests (e.g. gravatar) reach the internet.
 */
export async function newVisitorContext(browser: Browser, options: BrowserContextOptions = {}): Promise<BrowserContext> {
	const context = await browser.newContext(options);
	await blockThirdPartyHosts(context);
	return context;
}

/**
 * Collects `pageerror` and console.error entries of `page` into `sink`,
 * minus the expected noise (blocked hosts, CONSOLE_ALLOWLIST). The fixture
 * arms it on every page of its context; a spec that opens a page in another
 * context (newVisitorContext) arms it itself and asserts on its own sink.
 */
export function attachTripwire(page: Page, sink: string[]): void {
	page.on('pageerror', (error) => {
		// A page that throws a non-Error (`throw {…}`) gives us an error whose
		// message is the useless "[object Object]" — that is exactly what a
		// WebKit CI failure has reported. Prefer the stack, and fall
		// back to serialising the payload so the attachment names something.
		let detail = error?.stack || [error?.name, error?.message].filter(Boolean).join(': ');
		if (!detail || /\[object Object\]/.test(detail)) {
			try {
				detail = `${detail} ${JSON.stringify(error, Object.getOwnPropertyNames(error ?? {}))}`.trim();
			} catch {
				detail = String(error);
			}
		}
		sink.push(`pageerror: ${detail}`);
	});
	page.on('console', (msg) => {
		if (msg.type() !== 'error') {
			return;
		}
		const location = msg.location();
		const text = `console.error: ${msg.text()} @ ${location.url}:${location.lineNumber}`;
		if (BLOCKED_HOSTS.test(safeHostname(location.url))) {
			return; // aborted third-party request — expected
		}
		if (CONSOLE_ALLOWLIST.some((re) => re.test(text))) {
			return;
		}
		sink.push(text);
	});
}

function safeHostname(url: string): string {
	try {
		return new URL(url).hostname;
	} catch {
		return '';
	}
}

export const test = base.extend<Fixtures>({
	allowConsoleErrors: [false, { option: true }],

	consoleErrors: [
		async ({ context, allowConsoleErrors }, use, testInfo) => {
			const errors: string[] = [];

			// Hermetic routing for every page in this context.
			await blockThirdPartyHosts(context);

			// Tripwire on pages that already exist and on every page opened later.
			context.pages().forEach((page) => attachTripwire(page, errors));
			context.on('page', (page) => attachTripwire(page, errors));

			await use(errors);

			if (errors.length > 0) {
				await testInfo.attach('js-errors.txt', { body: errors.join('\n'), contentType: 'text/plain' });
			}
			if (!allowConsoleErrors) {
				expect(
					errors,
					'JS TRIPWIRE: uncaught errors / console.error during the test (see js-errors.txt attachment)',
				).toEqual([]);
			}
		},
		{ auto: true },
	],

	spio: async ({ request, baseURL }, use) => {
		await use(new SpioSupport(request, baseURL || 'http://localhost:8030'));
	},
});

export { expect };
