/**
 * Plain WordPress admin helpers (login, navigation).
 */
import { expect, type Page } from '@playwright/test';

export const ADMIN_USER = 'admin';
export const ADMIN_PASSWORD = 'password';

/** Log in through the real wp-login.php form. */
export async function login(page: Page, user = ADMIN_USER, password = ADMIN_PASSWORD): Promise<void> {
	await page.goto('/wp-login.php');
	await page.locator('#user_login').fill(user);
	await page.locator('#user_pass').fill(password);
	await page.locator('#wp-submit').click();
	await page.waitForURL(/\/wp-admin\/?/);
	await expect(page.locator('#wpadminbar')).toBeVisible();
}
