/**
 * Page object for the SPIO settings page
 * (wp-admin/options-general.php?page=wp-shortpixel-settings).
 *
 * Selector facts (verified in the Wave 1 exploration, see the plan file):
 *   - root:        `.wrap.is-shortpixel-settings-page` + view-mode class
 *                  (`simple` | `advanced` | `onboarding` | `page-quick-tour`)
 *   - sections:    `section.setting-tab[data-part="<part>"]`, `.active` = shown
 *   - menu links:  `menu a[data-menu-link="<part>"]`; the `exclusions`,
 *                  `processing`, `integrations`, `tools` items are hidden in
 *                  simple mode (`li.is-advanced { display:none }`)
 *   - tab switch:  client-side; dispatches window event
 *                  `shortpixel.ui.settingsTabLoad` {tabName}
 *   - save:        every tab embeds `.save-buttons button.save` (no id/name);
 *                  the AJAX save succeeds when `section.ajax-save-done` gets
 *                  class `show` for 2000ms — it is ALWAYS display:flex, so
 *                  assert the class, never visibility. Never click
 *                  `button.save-bulk` (redirects to the bulk page).
 *   - switches:    `input.switch[name="<setting>"]` inside `<switch><label>`,
 *                  visually covered by `div.the_switch` → check({force:true})
 *   - mode switch: `#viewmode-toggles input[type=checkbox]`, persisted as a
 *                  user option, applied to the root class immediately
 */
import { expect, type Locator, type Page } from '@playwright/test';
import { adminUrls, setChecked, withSpioEvent } from './spio';

export const SETTINGS_PARTS = [
	'overview',
	'optimisation',
	'exclusions',
	'processing',
	'webp',
	'ai',
	'integrations',
	'tools',
	'help',
] as const;
export type SettingsPart = (typeof SETTINGS_PARTS)[number];

/** Parts whose menu item only exists in advanced mode. */
export const ADVANCED_ONLY_PARTS: readonly SettingsPart[] = ['exclusions', 'processing', 'integrations', 'tools'];

export class SettingsPage {
	constructor(readonly page: Page) {}

	get root(): Locator {
		return this.page.locator('.wrap.is-shortpixel-settings-page');
	}

	section(part: SettingsPart): Locator {
		return this.page.locator(`section.setting-tab[data-part="${part}"]`);
	}

	menuLink(part: SettingsPart): Locator {
		return this.page.locator(`menu a[data-menu-link="${part}"]`);
	}

	/** Load the page, optionally directly on a tab (server-side `part=`). */
	async goto(part?: SettingsPart): Promise<void> {
		await this.page.goto(part ? `${adminUrls.settings}&part=${part}` : adminUrls.settings);
		await expect(this.root).toBeVisible();
	}

	/** Client-side tab switch through the menu; waits for SPIO's own event. */
	async switchTab(part: SettingsPart): Promise<void> {
		await withSpioEvent(this.page, 'shortpixel.ui.settingsTabLoad', () => this.menuLink(part).click());
		await expect(this.section(part)).toHaveClass(/active/);
		await expect(this.section(part)).toBeVisible();
	}

	async isAdvanced(): Promise<boolean> {
		const cls = (await this.root.getAttribute('class')) || '';
		return /\badvanced\b/.test(cls);
	}

	/**
	 * Toggle simple/advanced. SwitchViewModeEvent flips the root class
	 * synchronously but persists the mode with a fire-and-forget AJAX call
	 * (settings/changemode via the Web Worker) — so besides the class we wait
	 * for the processor's response event, otherwise a reload right after the
	 * toggle can race the user-option write (flaky on CI, 2026-09-15).
	 */
	async setViewMode(mode: 'simple' | 'advanced'): Promise<void> {
		const toggle = this.page.locator('#viewmode-toggles input[type="checkbox"]');
		if (await this.isAdvanced() === (mode === 'advanced')) {
			return; // already there — no request would be sent
		}
		await withSpioEvent(this.page, 'shortpixel.processor.responseHandled', async () => {
			if (mode === 'advanced') {
				await toggle.check({ force: true });
			} else {
				await toggle.uncheck({ force: true });
			}
		});
		await expect(this.root).toHaveClass(new RegExp(`\\b${mode}\\b`));
	}

	saveButton(part: SettingsPart): Locator {
		return this.section(part).locator('.save-buttons button.save');
	}

	get saveBanner(): Locator {
		return this.page.locator('section.ajax-save-done');
	}

	/** Click the tab's Save and wait for the "Settings successfully saved!" banner. */
	async save(part: SettingsPart): Promise<void> {
		await this.saveButton(part).click();
		await expect(this.saveBanner, 'the AJAX save must show the success banner').toHaveClass(/\bshow\b/, {
			timeout: 15_000,
		});
	}

	switchInput(name: string): Locator {
		return this.root.locator(`input.switch[name="${name}"]`);
	}

	/** Switch inputs are display:none behind `div.the_switch` → DOM-level set. */
	async setSwitch(name: string, on: boolean): Promise<void> {
		await setChecked(this.switchInput(name), on);
	}

	/** Radio groups (compressionType…) are custom-styled hidden inputs too. */
	async checkRadio(name: string, value: string): Promise<void> {
		await setChecked(this.root.locator(`input[type="radio"][name="${name}"][value="${value}"]`), true);
	}

	/** ≤782px only: the hamburger that reveals the (fixed) menu. */
	async openMobileMenu(): Promise<void> {
		const label = this.page.locator('label.mobile-menu');
		await expect(label).toBeVisible();
		await label.click();
		await expect(label).toHaveClass(/\bopened\b/);
	}

	/** Notices injected after the AJAX save or rendered after a reload. */
	notices(kind?: 'error' | 'success' | 'warning'): Locator {
		return this.page.locator(kind ? `.shortpixel-notice.notice-${kind}` : '.shortpixel-notice');
	}
}
