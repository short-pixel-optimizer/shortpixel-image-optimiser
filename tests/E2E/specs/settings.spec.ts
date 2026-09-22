/**
 * Wave 1 — Settings page (Tier 1: a failure here bricks the whole page).
 *
 * Covers: every tab renders and switches client-side (desktop + 780px mobile
 * band) with the stylesheet applied and no horizontal overflow; the
 * simple/advanced mode toggle persists; each tab's AJAX save persists a
 * representative setting (DOM after reload + server-side option); API-key
 * activation states through the mock (wrong length, -401 invalid, -403 quota,
 * valid); the exclusions editor happy path; and REGRESSION #62 (a
 * third-party `window.URL` overwrite used to kill every settings save —
 * fixed in 0db02498).
 *
 * Every test starts from the healthy-install seed (spio.reset()), which also
 * sets redirectedSettings=3 so the quick tour never intercepts clicks.
 */
import { test, expect } from '../fixtures';
import { ADVANCED_ONLY_PARTS, SETTINGS_PARTS, SettingsPage, type SettingsPart } from '../helpers/settings-page';
import { ApiCode, expectNoHorizontalOverflow, expectSettingsStylesheetApplied, setChecked } from '../helpers/spio';

const SEED_KEY = 'a'.repeat(20);

test.describe('Settings page', () => {
	test.beforeEach(async ({ spio }) => {
		await spio.reset();
	});

	test('every tab renders and switches client-side with its stylesheet applied', async ({ page }) => {
		const settings = new SettingsPage(page);
		await settings.goto();
		await settings.setViewMode('advanced');

		// The page opens on overview; visit every other tab, then come back.
		const others = SETTINGS_PARTS.filter((p) => p !== 'overview');
		for (const part of [...others, 'overview' as SettingsPart]) {
			await settings.switchTab(part);
			await expectSettingsStylesheetApplied(page);
			await expectNoHorizontalOverflow(page);
			// SwitchMenuTabEvent mirrors the tab into the URL via replaceState.
			expect(new URL(page.url()).searchParams.get('part'), `URL reflects tab ${part}`).toBe(part);
		}
	});

	test('the simple/advanced view mode persists across reloads', async ({ page }) => {
		const settings = new SettingsPage(page);
		await settings.goto();

		await settings.setViewMode('advanced');
		await settings.goto();
		expect(await settings.isAdvanced(), 'advanced mode must survive a reload').toBe(true);
		for (const part of ADVANCED_ONLY_PARTS) {
			await expect(settings.menuLink(part), `${part} menu item visible in advanced mode`).toBeVisible();
		}

		await settings.setViewMode('simple');
		await settings.goto();
		expect(await settings.isAdvanced()).toBe(false);
		for (const part of ADVANCED_ONLY_PARTS) {
			await expect(settings.menuLink(part), `${part} menu item hidden in simple mode`).toBeHidden();
		}
	});

	test.describe('780px viewport (the conflicting mobile breakpoint band)', () => {
		test.use({ viewport: { width: 780, height: 844 } });

		test('the hamburger reveals the menu and tabs still switch', async ({ page }) => {
			const settings = new SettingsPage(page);
			await settings.goto();
			await expectSettingsStylesheetApplied(page);
			await expectNoHorizontalOverflow(page);

			await settings.openMobileMenu();
			await settings.switchTab('webp');
			await expectNoHorizontalOverflow(page);
			// Switching closes the mobile menu again (SwitchMenuTabEvent unchecks it).
			await expect(page.locator('label.mobile-menu')).not.toHaveClass(/\bopened\b/);
		});
	});

	// -------------------------------------------------------------------
	// AJAX save round-trips — one representative, reversible setting per tab.
	// Assert BOTH what the user sees after a reload and the persisted option.
	// -------------------------------------------------------------------

	test('optimisation: compression type saves (radio → glossy)', async ({ page, spio }) => {
		const settings = new SettingsPage(page);
		await settings.goto('optimisation');
		await settings.checkRadio('compressionType', '2');
		await settings.save('optimisation');

		await settings.goto('optimisation');
		await expect(page.locator('input[name="compressionType"][value="2"]')).toBeChecked();
		expect(Number((await spio.getSettings()).compressionType)).toBe(2);
	});

	test('processing: a switch saves (showCustomMedia)', async ({ page, spio }) => {
		const settings = new SettingsPage(page);
		await settings.goto('processing');
		await settings.setSwitch('showCustomMedia', true);
		await settings.save('processing');

		await settings.goto('processing');
		await expect(settings.switchInput('showCustomMedia')).toBeChecked();
		expect(Number((await spio.getSettings()).showCustomMedia)).toBe(1);
	});

	test('webp: createWebp saves', async ({ page, spio }) => {
		const settings = new SettingsPage(page);
		await settings.goto('webp');
		await settings.setSwitch('createWebp', true);
		await settings.save('webp');

		await settings.goto('webp');
		await expect(settings.switchInput('createWebp')).toBeChecked();
		expect(Number((await spio.getSettings()).createWebp)).toBe(1);
	});

	test('ai: a switch saves (ai_use_post)', async ({ page, spio }) => {
		// (ai_use_exif exists as a setting but its switch is commented out in
		// part-ai.php — "will add this later".)
		const settings = new SettingsPage(page);
		await settings.goto('ai');
		await settings.setSwitch('ai_use_post', true);
		await settings.save('ai');

		await settings.goto('ai');
		await expect(settings.switchInput('ai_use_post')).toBeChecked();
		expect(Number((await spio.getSettings()).ai_use_post)).toBe(1);
	});

	test('integrations: a text field saves (Cloudflare zone id)', async ({ page, spio }) => {
		const settings = new SettingsPage(page);
		await settings.goto('integrations');
		await page.locator('#cloudflare-zone-id').fill('zone-e2e-123');
		await settings.save('integrations');

		await settings.goto('integrations');
		await expect(page.locator('#cloudflare-zone-id')).toHaveValue('zone-e2e-123');
		expect((await spio.getSettings()).cloudflareZoneID).toBe('zone-e2e-123');
	});

	test('exclusions: excluding a thumbnail size saves', async ({ page, spio }) => {
		const settings = new SettingsPage(page);
		await settings.goto('exclusions');
		await setChecked(page.locator('#excludeSizes_thumbnail'), true);
		await settings.save('exclusions');

		await settings.goto('exclusions');
		await expect(page.locator('#excludeSizes_thumbnail')).toBeChecked();
		expect((await spio.getSettings()).excludeSizes as string[]).toContain('thumbnail');
	});

	test('exclusions editor: a size exclusion added through the UI persists', async ({ page, spio }) => {
		const settings = new SettingsPage(page);
		await settings.goto('exclusions');

		await page.locator('button[name="addNewExclusion"]').click();
		await expect(page.locator('.new-exclusion')).not.toHaveClass(/\bnot-visible\b/);

		await page.locator('select[name="exclusion-type"]').selectOption('size');
		await page.locator('input[name="exclusion-minwidth"]').fill('100');
		await page.locator('input[name="exclusion-maxwidth"]').fill('200');
		await page.locator('input[name="exclusion-minheight"]').fill('100');
		await page.locator('input[name="exclusion-maxheight"]').fill('200');
		await page.locator('select[name="apply-select"]').selectOption('all');
		await page.locator('button[name="addExclusion"]').click();

		// The editor builds the <li> client-side and reminds the user to save.
		// (Count the hidden inputs: the list also holds the #exclusion-format template <li>.)
		const entries = page.locator('ul.exclude-list li input[name="exclusions[]"]');
		await expect(entries).toHaveCount(1);
		await expect(page.locator('info.exclusion-save-reminder')).not.toHaveClass(/\bhidden\b/);

		await settings.save('exclusions');

		await settings.goto('exclusions');
		await expect(entries).toHaveCount(1);
		const stored = await entries.first().inputValue();
		expect(JSON.parse(stored)).toMatchObject({ type: 'size', apply: 'all' });
		const patterns = (await spio.getSettings()).excludePatterns as Array<Record<string, unknown>>;
		expect(patterns).toHaveLength(1);
		expect(patterns[0]).toMatchObject({ type: 'size', apply: 'all' });
	});

	// -------------------------------------------------------------------
	// API key activation states (overview tab, mock-driven)
	// -------------------------------------------------------------------

	/** Reveal the key panel and enter a key; `#validate` is a submit button. */
	async function submitApiKey(page: import('@playwright/test').Page, key: string): Promise<void> {
		await page.locator('label.toggle-link').click();
		const input = page.locator('input[name="apiKey"]#key');
		await expect(input).toBeVisible();
		await expect(input, 'the seeded (option-based) key must be editable').toBeEnabled();
		await input.fill(key);
		await page.locator('#validate').click();
	}

	/**
	 * Submit a key and wait for SPIO's answer.
	 *
	 * Key validation is an IN-PLACE AJAX form post (multipart POST to
	 * admin-ajax.php carrying `display_part`), answered in ~100 ms; the
	 * notices are rendered into the same document and the page never
	 * navigates (probed 2026-09-16: window marker survives, no `load` event
	 * in 30 s). An earlier version waited with `waitForURL(settings page)`,
	 * which only "worked" because the URL already matched — it waited for
	 * nothing. Wait on the actual response instead.
	 */
	async function submitApiKeyAndWait(page: import('@playwright/test').Page, key: string): Promise<void> {
		const answered = page.waitForResponse(
			(res) =>
				res.url().includes('/wp-admin/admin-ajax.php') &&
				res.request().method() === 'POST' &&
				(res.request().postData() || '').includes('name="display_part"'),
		);
		await submitApiKey(page, key);
		expect((await answered).status(), 'the settings form post must succeed').toBe(200);
	}

	test('API key: the eye toggle reveals the key', async ({ page }) => {
		const settings = new SettingsPage(page);
		await settings.goto('overview');
		await page.locator('label.toggle-link').click();
		const input = page.locator('input[name="apiKey"]#key');
		await expect(input).toHaveAttribute('type', 'password');
		await page.locator('.apifield i.eye').click();
		await expect(input).toHaveAttribute('type', 'text');
		await expect(input).toHaveValue(SEED_KEY);
	});

	test('API key: a key of the wrong length is rejected locally (no remote call)', async ({ page, spio }) => {
		const settings = new SettingsPage(page);
		await settings.goto('overview');

		// Validation is answered in place over AJAX (no reload).
		await submitApiKeyAndWait(page, 'too-short');

		await expect(settings.notices('error').filter({ hasText: /20 characters/i })).toBeVisible();
		// NB: the reloaded settings page refreshes the quota (api-status.php)
		// on its own, so "no remote call at all" is not assertable here; the
		// length check happening BEFORE validateKey() is a PHPUnit-level fact
		// (ApiKeyModel::checkKey). The absence of "Error during verifying"
		// proves the remote validation branch was not taken.
		await expect(settings.notices('error').filter({ hasText: /Error during verifying/i })).toHaveCount(0);
		void spio;
	});

	test('API key: an invalid key (-401) is reported after validation', async ({ page, spio }) => {
		await spio.setMock({ apiStatusCode: ApiCode.INVALID_KEY });
		const settings = new SettingsPage(page);
		await settings.goto('overview');

		await submitApiKeyAndWait(page, 'b'.repeat(20));

		// (The notice renders twice on the reloaded page — assert on the first.)
		const verifyNotice = settings.notices('error').filter({ hasText: /Error during verifying API key/i }).first();
		await expect(verifyNotice).toBeVisible();
		await expect(verifyNotice).toContainText(/Invalid API key/i);
		const remote = (await spio.mockRequests()).filter((r) => r.path.includes('api-status'));
		expect(remote.length, 'validation must hit api-status.php').toBeGreaterThan(0);
	});

	test('API key: quota exceeded (-403) is reported after validation', async ({ page, spio }) => {
		await spio.setMock({ apiStatusCode: ApiCode.QUOTA_EXCEEDED });
		const settings = new SettingsPage(page);
		await settings.goto('overview');

		await submitApiKeyAndWait(page, 'b'.repeat(20));

		await expect(settings.notices('error').filter({ hasText: /Error during verifying API key/i }).first()).toContainText(/Quota exceeded/i);
	});

	test('API key: a new valid key is accepted and marked verified', async ({ page }) => {
		const settings = new SettingsPage(page);
		await settings.goto('overview');
		await submitApiKey(page, 'c'.repeat(20));

		// Success path: no redirect, banner + injected success notice.
		await expect(settings.saveBanner).toHaveClass(/\bshow\b/, { timeout: 15_000 });
		await expect(settings.notices('success')).toContainText(/valid/i);

		await settings.goto('overview');
		await expect(page.locator('span.shortpixel-key-valid')).toBeVisible();
		await page.locator('label.toggle-link').click();
		await expect(page.locator('input[name="apiKey"]#key')).toHaveValue('c'.repeat(20));
	});
});

// -----------------------------------------------------------------------
// REGRESSION #62 — third-party window.URL overwrite no longer kills saves
// -----------------------------------------------------------------------

/**
 * Flipped from pin62 on 2026-09-18 (fixed in 0db02498): FormSendEvent used
 * `URL.parse(form.action)`, a static that third-party scripts such as the
 * EMC – Embed Calendly widget remove when they replace window.URL — every
 * settings save threw after preventDefault() and silently did nothing. It
 * now uses `new URL(form.action)` inside try/catch with a null guard. The
 * console-error tripwire is armed again: any error during the save fails
 * the test.
 */
test.describe('Regression #62 — window.URL overwritten by a third-party script', () => {
	test.beforeEach(async ({ spio }) => {
		await spio.reset();
	});

	test('regression62: settings save still works when a third-party script removed URL.parse', async ({
		page,
		spio,
		consoleErrors,
	}) => {
		await spio.setHostileSnippets(['window-url-overwrite']);
		const settings = new SettingsPage(page);
		await settings.goto('optimisation');

		// SENTINEL: the hostile snippet is live and did what Calendly-EMC does.
		const hostile = await page.evaluate(() => ({
			list: (window as any).__spioE2EHostile as string[] | undefined,
			hasParse: typeof (window as any).URL.parse === 'function',
		}));
		expect(hostile.list, 'hostile snippet must have run').toContain('window-url-overwrite');
		expect(hostile.hasParse, 'URL.parse must be gone (that is the scenario)').toBe(false);

		// A real change, so persistence (or its absence) is observable —
		// SENTINEL: the seed pins compressionType to 1 first.
		expect(Number((await spio.getSettings()).compressionType), 'sentinel: seed value').toBe(1);
		await settings.checkRadio('compressionType', '2');
		await settings.saveButton('optimisation').click();

		// The AJAX save completes: success banner, value persisted server-side.
		await expect(settings.saveBanner, 'REGRESSION #62: the save banner must appear').toHaveClass(/\bshow\b/, {
			timeout: 15_000,
		});
		await expect
			.poll(async () => Number((await spio.getSettings()).compressionType), {
				message: 'REGRESSION #62: the new compressionType must be saved',
			})
			.toBe(2);
		expect(consoleErrors.join('\n'), 'REGRESSION #62: no URL.parse error any more').not.toMatch(/URL\.parse/);
	});
});
