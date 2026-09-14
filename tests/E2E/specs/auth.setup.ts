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

	// storageState captures localStorage too. The dashboard the login lands on
	// already ran SPIO's processor, which wrote its `bulkSecret` lock key
	// there; carrying that key into every test made the processor's
	// CheckActive() see a mismatch against the server-side key and park the
	// queue (flaky optimize round-trip, 2026-09-14). Persist COOKIES only —
	// each test's pages start with a clean localStorage and become the
	// processor on their own (the support endpoint's reset() clears the
	// server half of the lock).
	await page.evaluate(() => localStorage.clear());
	await page.context().storageState({ path: STORAGE_STATE });
});
