/**
 * Page object for the no-key onboarding view of the settings page
 * (class/view/settings/part-nokey.php + res/js/shortpixel-onboarding.js).
 *
 * Facts (Wave 2 exploration):
 *   - shown when spio_key.verifiedKey is false: root gets class `onboarding`,
 *     `#tab-nokey` is the active section, the tab menu is hidden;
 *   - two panels are BOTH visible; `now-active` (toggled by clicking a panel)
 *     decides which branch AddKeyEvent takes — `settinglist.new-customer`
 *     (default) or `settinglist.existing-customer`;
 *   - one submit: `button[name="add-key"]` ("Continue"), `.submitting` in flight;
 *   - existing key → screen_action action_addkey; success → json.redirect
 *     (absolute settings URL) applied after a hard 3000ms timeout; failure →
 *     notices in `div.submit-errors.is-visible`, no navigation;
 *   - new account → local validation (email regex, #tos) → no request on
 *     failure; success → POST https://shortpixel.com/free-sign-up-plugin
 *     (server-side, answered by the mock's signupStatus knob).
 */
import { expect, type Locator, type Page } from '@playwright/test';
import { adminUrls } from './spio';

export class OnboardingPage {
	constructor(readonly page: Page) {}

	get root(): Locator {
		return this.page.locator('.wrap.is-shortpixel-settings-page');
	}

	async goto(): Promise<void> {
		await this.page.goto(adminUrls.settings);
		await expect(this.root).toHaveClass(/\bonboarding\b/);
		await expect(this.page.locator('#tab-nokey')).toHaveClass(/\bactive\b/);
	}

	get newCustomerPanel(): Locator {
		return this.page.locator('settinglist.new-customer');
	}

	get existingCustomerPanel(): Locator {
		return this.page.locator('settinglist.existing-customer');
	}

	async useExistingKey(): Promise<void> {
		await this.existingCustomerPanel.click();
		await expect(this.existingCustomerPanel).toHaveClass(/\bnow-active\b/);
	}

	async useNewAccount(): Promise<void> {
		await this.newCustomerPanel.click();
		await expect(this.newCustomerPanel).toHaveClass(/\bnow-active\b/);
	}

	get keyInput(): Locator {
		return this.page.locator('#new-key');
	}

	get emailInput(): Locator {
		return this.page.locator('#pluginemail');
	}

	get tos(): Locator {
		return this.page.locator('#tos');
	}

	get submit(): Locator {
		return this.page.locator('button[name="add-key"]');
	}

	get errors(): Locator {
		return this.page.locator('div.submit-errors');
	}
}
