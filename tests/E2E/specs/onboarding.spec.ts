/**
 * Wave 2 — Onboarding / API-key activation + quick tour (Tier 2).
 *
 * The no-key state is the first thing every new user sees. Covers: the
 * first-run redirect to the settings page, the existing-key panel (valid →
 * reload into keyed settings; -401 → error notice, no navigation), the
 * new-account panel (local validation: bad email / TOS unchecked → no
 * request; success → account created through the mocked
 * free-sign-up-plugin endpoint; "existing" → warning), and the quick tour
 * walked once end to end.
 */
import { test, expect } from '../fixtures';
import { OnboardingPage } from '../helpers/onboarding';
import { SettingsPage } from '../helpers/settings-page';
import { adminUrls, ApiCode, withSelfReload } from '../helpers/spio';

/**
 * In the no-key state SPIO's processor logs `console.error('No API Key set
 * for this site. See settings')` on every admin page (shortpixel-processor.js
 * ~:470). That is the documented no-key behaviour, not a defect, so the
 * tripwire's teardown check is relaxed here and each test asserts instead
 * that this is the ONLY console error it produced.
 */
const NO_KEY_NOISE = /No API Key set for this site/;

async function expectOnlyNoKeyNoise(consoleErrors: string[]): Promise<void> {
	expect(consoleErrors.filter((e) => !NO_KEY_NOISE.test(e)), 'no console errors besides the expected no-key notice').toEqual([]);
}

test.describe('Onboarding (no API key)', () => {
	test.use({ allowConsoleErrors: true });

	test.beforeEach(async ({ spio }) => {
		await spio.reset();
		await spio.setKeyState('none');
	});

	test.afterEach(async ({ consoleErrors }) => {
		await expectOnlyNoKeyNoise(consoleErrors);
	});

	test('first admin page load redirects to the settings page exactly once', async ({ page, spio }) => {
		await spio.setKeyState('none', { redirectedSettings: 0 });
		await page.goto(adminUrls.dashboard);
		await expect(page).toHaveURL(/page=wp-shortpixel-settings/);
		await expect(page.locator('.wrap.is-shortpixel-settings-page')).toHaveClass(/\bonboarding\b/);

		// Second visit: no redirect anymore.
		await page.goto(adminUrls.dashboard);
		await expect(page).toHaveURL(/wp-admin\/?$/);
	});

	test('existing valid key: Continue validates it and reloads into the keyed settings', async ({ page, spio }) => {
		const onboarding = new OnboardingPage(page);
		await onboarding.goto();
		await onboarding.useExistingKey();
		await onboarding.keyInput.fill('k'.repeat(20));

		// The onboarding screen IS the settings page, so the redirect target
		// matches the current URL — wait for the document, not the URL.
		// FormAddKeyResponse applies json.redirect after a hard 3000ms.
		await withSelfReload(page, () => onboarding.submit.click(), 30_000);

		await expect(page.locator('.wrap.is-shortpixel-settings-page')).not.toHaveClass(/\bonboarding\b/);
		// (part-nokey is always rendered inside the form; it is just not the active section.)
		await expect(page.locator('#tab-nokey')).not.toHaveClass(/\bactive\b/);
		await expect(page.locator('#tab-overview')).toHaveClass(/\bactive\b/);
		await expect(page.locator('span.shortpixel-key-valid')).toBeVisible();
	});

	test('existing invalid key (-401): error notice, no navigation', async ({ page, spio }) => {
		await spio.setMock({ apiStatusCode: ApiCode.INVALID_KEY });
		const onboarding = new OnboardingPage(page);
		await onboarding.goto();
		await onboarding.useExistingKey();
		await onboarding.keyInput.fill('k'.repeat(20));
		await onboarding.submit.click();

		await expect(onboarding.errors).toHaveClass(/\bis-visible\b/, { timeout: 15_000 });
		await expect(onboarding.errors.locator('.shortpixel-notice.notice-error')).toContainText(/Error during verifying API key/i);
		await expect(onboarding.submit).not.toHaveClass(/\bsubmitting\b/);
		await page.waitForTimeout(3_500); // past the redirect timer
		await expect(page.locator('.wrap.is-shortpixel-settings-page')).toHaveClass(/\bonboarding\b/);
	});

	test('new account: an invalid email is rejected locally without any request', async ({ page, spio }) => {
		const onboarding = new OnboardingPage(page);
		await onboarding.goto();
		await onboarding.useNewAccount();
		await onboarding.emailInput.fill('not-an-email');
		await onboarding.tos.check({ force: true });
		const before = (await spio.mockRequests()).length;
		await onboarding.submit.click();

		await expect(page.locator('#pluginemail-error')).toBeVisible();
		await expect(onboarding.emailInput).toHaveClass(/\binvalid\b/);
		await expect(onboarding.submit).not.toHaveClass(/\bsubmitting\b/);
		await page.waitForTimeout(1_000);
		expect((await spio.mockRequests()).length, 'no request must leave the page').toBe(before);
	});

	test('new account: unchecked terms are flagged and nothing is sent', async ({ page, spio }) => {
		const onboarding = new OnboardingPage(page);
		await onboarding.goto();
		await onboarding.useNewAccount();
		await onboarding.emailInput.fill('someone@example.com');
		await onboarding.tos.uncheck({ force: true });
		const before = (await spio.mockRequests()).length;
		await onboarding.submit.click();

		await expect(onboarding.tos).toHaveClass(/\binvalid\b/);
		await expect(page.locator('img.tos-robo')).toBeVisible({ timeout: 5_000 });
		await page.waitForTimeout(1_000);
		expect((await spio.mockRequests()).length).toBe(before);
	});

	test('new account: a successful signup stores the new key and reloads', async ({ page, spio }) => {
		await spio.setMock({ signupStatus: 'success' });
		const onboarding = new OnboardingPage(page);
		await onboarding.goto();
		await onboarding.useNewAccount();
		await onboarding.emailInput.fill('someone@example.com');
		await onboarding.tos.check({ force: true });

		await withSelfReload(page, () => onboarding.submit.click(), 45_000);

		await expect(page.locator('.wrap.is-shortpixel-settings-page')).not.toHaveClass(/\bonboarding\b/);
		const signup = (await spio.mockRequests()).filter((r) => r.path.includes('free-sign-up-plugin'));
		expect(signup.length, 'the signup endpoint must have been called').toBeGreaterThan(0);
	});

	test('new account: an email already in use shows a warning and stays on onboarding', async ({ page, spio }) => {
		await spio.setMock({ signupStatus: 'existing' });
		const onboarding = new OnboardingPage(page);
		await onboarding.goto();
		await onboarding.useNewAccount();
		await onboarding.emailInput.fill('someone@example.com');
		await onboarding.tos.check({ force: true });
		await onboarding.submit.click();

		await expect(onboarding.errors).toHaveClass(/\bis-visible\b/, { timeout: 15_000 });
		await expect(onboarding.errors.locator('.shortpixel-notice')).toContainText(/already in use/i);
		await page.waitForTimeout(3_500);
		await expect(page.locator('.wrap.is-shortpixel-settings-page')).toHaveClass(/\bonboarding\b/);
	});
});

test.describe('Quick tour', () => {
	test.beforeEach(async ({ spio }) => {
		await spio.reset();
		// Verified key + tour not yet finished (redirectedSettings < 3).
		await spio.setKeyState('verified');
		await spio.setSettings({ redirectedSettings: 2 });
	});

	test('walks all five steps, switching tabs, and finishing persists the flag', async ({ page, spio, browserName }) => {
		test.skip(browserName === 'webkit', 'WebKit reloads the page on Next — see the pinned test below');
		const settings = new SettingsPage(page);
		await settings.goto();
		await expect(settings.root).toHaveClass(/\bpage-quick-tour\b/);
		const tour = page.locator('div.quick-tour');
		await expect(tour).toBeVisible();
		await expect(tour.locator('.step.step-0')).toHaveClass(/\bactive\b/);
		// step-0's `active` class is server-rendered, so it proves nothing
		// about JS. The click handlers are attached by InitQuickTour(), which
		// only runs on `shortpixel.settings.loaded` and ends by adding
		// `active-step-0` to the root — wait for THAT before clicking, so the
		// first click can never land before a listener exists.
		await expect(settings.root).toHaveClass(/\bactive-step-0\b/);

		const next = tour.locator('.navigation button.next');
		for (let step = 1; step <= 4; step++) {
			await next.click();
			await expect(tour.locator(`.step.step-${step}`)).toHaveClass(/\bactive\b/);
			await expect(settings.root).toHaveClass(new RegExp(`\\bactive-step-${step}\\b`));
			await expect(tour.locator(`a.stepdot[data-step="${step}"]`)).toHaveClass(/\bactive\b/);
		}
		// Last step: Next is hidden, the finish button is revealed; the tour
		// drove the tab menu along the way (step-4 → help).
		await expect(next).toHaveClass(/\bhide\b/);
		const finish = tour.locator('.navigation button.close');
		await expect(finish).not.toHaveClass(/\bhide\b/);
		await expect(settings.section('help')).toHaveClass(/\bactive\b/);

		await withSelfReload(page, () => finish.click());
		await expect(page.locator('div.quick-tour')).toHaveCount(0);
		await expect(settings.root).not.toHaveClass(/\bpage-quick-tour\b/);
		expect(Number((await spio.getSettings()).redirectedSettings)).toBe(3);
	});

	/**
	 * PIN (unnumbered — E2E seed finding, WebKit/Safari only):
	 * shortpixel-onboarding.js QuickTourSwitchToItem() switches the settings
	 * tab by dispatching `new CustomEvent('click')` on the menu's
	 * `<a href="…&part=<tab>" data-menu-link>`. That event is NOT
	 * cancelable, so the preventDefault() in SettingsPage's
	 * SwitchMenuTabEvent is a no-op. Chromium and Firefox never run a link's
	 * activation behaviour for a CustomEvent; WebKit does — it follows the
	 * href. Result on WebKit: clicking "Start Tour" reloads the settings
	 * page at `&part=overview` and the tour starts over at step 0, forever.
	 * Engine behaviour verified in isolation on a bare page (2026-09-16).
	 * Likely fix: dispatch `new MouseEvent('click', { cancelable: true })`,
	 * or call the settings tab switch directly instead of faking a click.
	 *
	 * FLIP-when-fixed: this test fails (no navigation), then delete it and
	 * the webkit skip in the test above.
	 */
	test('pin: WebKit reloads the page on Next and the tour never advances (pinned_for_deferred_fix)', async ({ page, browserName }) => {
		test.skip(browserName !== 'webkit', 'WebKit-only defect');
		const settings = new SettingsPage(page);
		await settings.goto();
		const tour = page.locator('div.quick-tour');
		await expect(settings.root).toHaveClass(/\bactive-step-0\b/); // listeners attached
		expect(page.url(), 'starting URL carries no part= parameter').not.toMatch(/part=/);
		await page.evaluate(() => {
			(window as any).__spioSameDocument = true;
		});

		// SENTINEL: the click took SPIO's tab-switch path — the navigation
		// target is exactly the menu link of step 1's screen (overview).
		await Promise.all([
			page.waitForURL(/page=wp-shortpixel-settings&part=overview/, { waitUntil: 'load' }),
			tour.locator('.navigation button.next').click(),
		]);

		// PIN: a full reload happened (the marker is gone) and the tour
		// re-initialised at step 0 instead of advancing to step 1.
		expect(await page.evaluate(() => (window as any).__spioSameDocument), 'the page was reloaded').toBeUndefined();
		await expect(settings.root).toHaveClass(/\bactive-step-0\b/);
		await expect(page.locator('div.quick-tour .step.step-0')).toHaveClass(/\bactive\b/);
		await expect(page.locator('div.quick-tour .step.step-1')).not.toHaveClass(/\bactive\b/);
	});
});
