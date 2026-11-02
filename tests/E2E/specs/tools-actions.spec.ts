/**
 * Settings → Tools: the action links.
 *
 * Every Tools button is a plain GET link that the settings router dispatches
 * by its `sp-action` (`action_debug_resetQueue`, `action_debug_removePrevented`,
 * `action_debug_redirectBulk`, …). Each link carries a nonce for its own
 * action in `sp-nonce` (ViewController::getActionLink()), and the action
 * verifies it with checkRequest() before doing anything.
 *
 * Covers, in a real browser: the rendered links carry the nonce and work
 * (land back on the Tools tab with the success notice, or on the bulk panel);
 * the same link with the nonce removed or swapped for another action's nonce
 * stops at WordPress' "link you followed has expired" screen without running
 * the action.
 */
import { test, expect } from '../fixtures';
import type { Locator, Page } from '@playwright/test';
import { SettingsPage } from '../helpers/settings-page';

const EXPIRED = /The link you followed has expired/i;

async function openTools(page: Page): Promise<SettingsPage> {
	const settings = new SettingsPage(page);
	await settings.goto();
	await settings.setViewMode('advanced');
	await settings.goto('tools');
	await expect(settings.section('tools')).toBeVisible();
	return settings;
}

function toolLink(settings: SettingsPage, action: string, extra = ''): Locator {
	return settings.section('tools').locator(`a.button[href*="sp-action=${action}"]${extra}`);
}

async function hrefOf(link: Locator, page: Page): Promise<URL> {
	const href = await link.getAttribute('href');
	expect(href, 'the Tools link has an href').toBeTruthy();
	return new URL(href as string, page.url());
}

test.describe('Settings → Tools action links', () => {
	test.beforeEach(async ({ spio }) => {
		await spio.reset();
	});

	test('every Tools action link carries a nonce', async ({ page }) => {
		const settings = await openTools(page);
		const links = settings.section('tools').locator('a.button[href*="sp-action="]');
		const count = await links.count();
		// Clear the Queue, Clear Optimization Errors, Search and Migrate All,
		// Redo AI Replacement, Bulk Restore, Bulk Undo AI, Remove Legacy Data.
		expect(count, 'the Tools tab renders its action links').toBeGreaterThanOrEqual(7);

		for (let i = 0; i < count; i++) {
			const url = await hrefOf(links.nth(i), page);
			expect(url.searchParams.get('sp-nonce'), `${url.searchParams.get('sp-action')} carries sp-nonce`).toMatch(/^[0-9a-f]{10}$/);
			expect(url.searchParams.get('noheader'), 'noheader lets the action redirect before admin output').toBe('1');
		}
	});

	test('Clear the Queue runs and returns to the Tools tab', async ({ page }) => {
		const settings = await openTools(page);
		await toolLink(settings, 'action_debug_resetQueue').click();

		await expect(page).toHaveURL(/page=wp-shortpixel-settings/);
		expect(new URL(page.url()).searchParams.get('part')).toBe('tools');
		await expect(page.locator('body')).not.toContainText(EXPIRED);
		await expect(page.getByText(/All items in the queues have been removed/i).first()).toBeVisible();
	});

	test('Clear Optimization Errors runs and shows its notice', async ({ page }) => {
		const settings = await openTools(page);
		await toolLink(settings, 'action_debug_removePrevented').click();

		await expect(page).toHaveURL(/page=wp-shortpixel-settings/);
		await expect(page.locator('body')).not.toContainText(EXPIRED);
		await expect(page.getByText(/Item blocks have been removed/i).first()).toBeVisible();
	});

	test('Bulk Restore opens the restore panel of the bulk page', async ({ page }) => {
		const settings = await openTools(page);
		await toolLink(settings, 'action_debug_redirectBulk', '[href*="bulk=restore"]:not([href*="restoreAI"])').click();

		await expect(page).toHaveURL(/page=wp-short-pixel-bulk/);
		expect(new URL(page.url()).searchParams.get('panel')).toBe('bulk-restore');
	});

	// The browser logs the expected 403 of the refused link as a console
	// error, so these two opt out of the tripwire; the status is asserted.
	test.describe('refused links', () => {
		test.use({ allowConsoleErrors: true });

		test('a Tools link without its nonce stops at the expired-link screen', async ({ page }) => {
			const settings = await openTools(page);
			const url = await hrefOf(toolLink(settings, 'action_debug_resetQueue'), page);
			url.searchParams.delete('sp-nonce');

			const response = await page.goto(url.toString());
			expect(response?.status(), 'check_admin_referer() answers 403').toBe(403);
			await expect(page.locator('body')).toContainText(EXPIRED);
			await expect(page.getByText(/All items in the queues have been removed/i)).toHaveCount(0);
		});

		test("a Tools link carrying another action's nonce stops at the expired-link screen", async ({ page }) => {
			const settings = await openTools(page);
			const queueUrl = await hrefOf(toolLink(settings, 'action_debug_resetQueue'), page);
			const errorsUrl = await hrefOf(toolLink(settings, 'action_debug_removePrevented'), page);

			// The Clear-the-Queue nonce must not unlock Clear Optimization Errors.
			errorsUrl.searchParams.set('sp-nonce', queueUrl.searchParams.get('sp-nonce') as string);
			const response = await page.goto(errorsUrl.toString());

			expect(response?.status()).toBe(403);
			await expect(page.locator('body')).toContainText(EXPIRED);
		});
	});
});
