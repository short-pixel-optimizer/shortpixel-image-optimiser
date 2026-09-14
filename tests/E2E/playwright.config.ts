/**
 * Playwright configuration for the SPIO browser E2E suite.
 *
 * Run through bin/test-e2e.sh (Docker, canonical) or natively from this
 * directory against the Dockerized WordPress (`npx playwright test --headed`
 * with E2E_BASE_URL defaulting to http://localhost:8030).
 *
 * Serial by design: every spec shares ONE WordPress install and mutates it
 * (uploads, settings, queue). workers: 1 + fullyParallel: false keeps tests
 * from racing each other; the `spio` fixture resets state per test.
 */
import { defineConfig, devices } from '@playwright/test';

const baseURL = process.env.E2E_BASE_URL || 'http://localhost:8030';
const isCI = !!process.env.CI;

export default defineConfig({
	testDir: './specs',
	outputDir: './artifacts/test-results',

	fullyParallel: false,
	workers: 1,
	// One retry on CI only, and a retried pass is still visible in the report
	// as "flaky" — never silently green.
	retries: isCI ? 1 : 0,
	forbidOnly: isCI,

	timeout: 90_000,
	expect: { timeout: 15_000 },

	reporter: [
		['list'],
		['html', { outputFolder: './artifacts/html-report', open: 'never' }],
	],

	use: {
		baseURL,
		// Post-mortem material only when something went wrong.
		trace: 'retain-on-failure',
		screenshot: 'only-on-failure',
		video: 'retain-on-failure',
		actionTimeout: 15_000,
		navigationTimeout: 30_000,
	},

	projects: [
		{
			// Logs in once and stores the admin session for every other project.
			name: 'setup',
			testMatch: /.*\.setup\.ts/,
		},
		{
			name: 'chromium',
			use: {
				...devices['Desktop Chrome'],
				viewport: { width: 1366, height: 768 },
				storageState: './artifacts/.auth/admin.json',
			},
			dependencies: ['setup'],
		},
		// Wave 4 adds firefox + webkit projects here (same shape).
	],
});
