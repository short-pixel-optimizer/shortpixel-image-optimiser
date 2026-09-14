/**
 * Setup project: log in once as the provisioned admin and persist the
 * session, so every spec in the `chromium` project starts authenticated
 * (see storageState in playwright.config.ts).
 */
import { test as setup } from '@playwright/test';
import { login } from '../helpers/wp-admin';

const STORAGE_STATE = './artifacts/.auth/admin.json';

setup('authenticate as admin', async ({ page }) => {
	await login(page);
	await page.context().storageState({ path: STORAGE_STATE });
});
