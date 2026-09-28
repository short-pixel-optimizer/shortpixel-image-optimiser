/**
 * Page object for the SPIO bulk page (wp-admin/upload.php?page=wp-short-pixel-bulk).
 *
 * Selector facts (Wave 1 exploration):
 *   - panels:   `section.panel[data-panel]` (dashboard | selection | summary |
 *               process | finished | bulk-* specials); `.active` + inline
 *               display:block = shown; a 500ms opacity transition follows.
 *   - dashboard `#start-optimize` (data-action=open-panel → selection)
 *   - selection `[data-action="CreateBulk"]` "Calculate" (never auto-disabled),
 *               filters by id (#media_checkbox, #webp_checkbox, …), dates need
 *               the picker (dataset.formatteddate), not fill().
 *   - summary   numbers in `[data-stats-media|custom|total="<key>"]`;
 *               `[data-action="StartBulk"]` IS auto-disabled until data lands.
 *   - process   `[data-stats-media="percentage_done|in_queue|in_process|done|fatal_errors"]`,
 *               `#PauseBulkButton` / `#ResumeBulkButton` / `[data-action="StopBulk"]`
 *               (StopBulk uses a native confirm()), `#processPaused` overlay,
 *               error rows `.errorbox.media > div` (`.fatal` for terminal API
 *               errors), revealed by `input[name="show-errors"]`.
 *   - finished  `#FinishBulkButton` → server finishBulk → page reload to dashboard.
 *   - events    `shortpixel.PrepareBulk` (createBulk answered),
 *               `shortpixel.bulk.started`, `shortpixel.processor.responseHandled`
 *               (every tick), `shortpixel.processor.paused` {paused}.
 *   - timing    first API send per item is non-blocking and the row is only
 *               re-picked after ShortQ's 10s process_timeout → the driver loop
 *               backdates the queue between ticks (support endpoint).
 */
import { expect, type Locator, type Page } from '@playwright/test';
import { adminUrls, setChecked, withSelfReload, withSpioEvent, type SpioSupport } from './spio';

export type BulkPanel = 'dashboard' | 'selection' | 'summary' | 'process' | 'finished';

export class BulkPage {
	constructor(readonly page: Page) {}

	async goto(): Promise<void> {
		await this.page.goto(adminUrls.bulk);
		await expect(this.page.locator('div.screen-wrapper')).toBeVisible();
	}

	panel(name: BulkPanel): Locator {
		return this.page.locator(`section.panel[data-panel="${name}"]`);
	}

	/**
	 * Wait until a panel is BOTH active and visible, in one poll.
	 *
	 * Deliberately not two assertions: the screen can switch panels while a
	 * second assertion is still polling, which produced a baffling CI
	 * failure ("class active" passed, then the element was reported hidden
	 * with the class already gone, 2026-09-16). One predicate reports the
	 * real state and never straddles a switch.
	 */
	async expectPanel(name: BulkPanel, timeoutMs = 30_000): Promise<void> {
		// Resolves to the wanted name on success, and otherwise to a short
		// description of what IS on screen. Returning the name (rather than
		// a boolean) means a CI failure reports which panel actually won —
		// without that, a timeout said only "dashboard must be active" and
		// gave nothing to reason about (2026-09-22).
		await expect
			.poll(
				() =>
					this.page
						.evaluate((wanted) => {
							const all = Array.from(document.querySelectorAll('section.panel'));
							const target = all.find((el) => el.getAttribute('data-panel') === wanted);
							const visible = (el: Element) =>
								!!(el.getClientRects().length && getComputedStyle(el).visibility !== 'hidden');
							if (target && target.classList.contains('active') && visible(target)) {
								return wanted;
							}
							const active = all.find((el) => el.classList.contains('active'));
							const activeName = active ? active.getAttribute('data-panel') : 'none';
							const shown = all
								.filter(visible)
								.map((el) => el.getAttribute('data-panel'))
								.join(',');
							return `active=${activeName} visible=[${shown || 'none'}]`;
						}, name)
						.catch((e) => `evaluate failed: ${String(e)}`),
				{ timeout: timeoutMs, message: `bulk panel "${name}" must be the active, visible panel` },
			)
			.toBe(name);
	}

	stat(scope: 'media' | 'custom' | 'total', key: string): Locator {
		return this.page.locator(`[data-stats-${scope}="${key}"]`).first();
	}

	/** Numeric value of a stat (SPIO renders thousands separators). */
	async statNumber(scope: 'media' | 'custom' | 'total', key: string): Promise<number> {
		const text = (await this.stat(scope, key).textContent()) || '';
		return Number(text.replace(/[^\d.-]/g, '')) || 0;
	}

	/** dashboard → selection */
	async startSelection(): Promise<void> {
		await this.page.locator('#start-optimize').click();
		await this.expectPanel('selection');
	}

	/** selection → (prepare) → summary */
	async calculate(): Promise<void> {
		await withSpioEvent(this.page, 'shortpixel.PrepareBulk', () =>
			this.page.locator('[data-action="CreateBulk"]').click(),
		);
		await this.expectPanel('summary', 60_000);
	}

	/** summary → process (waits for the Start button to be enabled by the stats). */
	async startBulk(): Promise<void> {
		const button = this.page.locator('[data-action="StartBulk"]');
		await expect(button).toBeEnabled({ timeout: 30_000 });
		await withSpioEvent(this.page, 'shortpixel.bulk.started', () => button.click());
		await this.expectPanel('process');
	}

	/**
	 * Drive the process panel to completion: after every processor tick, age
	 * the queue rows so the next tick re-picks "sent to API" items without
	 * waiting out ShortQ's 10s process_timeout. Ends on the finished panel.
	 */
	async driveToFinish(spio: SpioSupport, maxTicks = 60): Promise<void> {
		for (let tick = 0; tick < maxTicks; tick++) {
			if (await this.panel('finished').evaluate((el) => el.classList.contains('active')).catch(() => false)) {
				break;
			}
			await spio.backdateQueue();
			try {
				await withSpioEvent(this.page, 'shortpixel.processor.responseHandled', async () => undefined, 25_000);
			} catch {
				// A missed tick (the processor may already have stopped on
				// QUEUE_EMPTY) — the loop head checks the finished panel again.
			}
		}
		await this.expectPanel('finished', 10_000);
	}

	async pause(): Promise<void> {
		await withSpioEvent(this.page, 'shortpixel.processor.paused', () =>
			this.page.locator('#PauseBulkButton').click(),
		);
		await expect(this.page.locator('#processPaused')).toBeVisible();
	}

	async resume(): Promise<void> {
		await withSpioEvent(this.page, 'shortpixel.processor.paused', () =>
			this.page.locator('#ResumeBulkButton').click(),
		);
		await expect(this.page.locator('#processPaused')).toBeHidden();
	}

	/**
	 * Stop = native confirm() + server finishBulk + a JS-triggered reload.
	 *
	 * The reload is NOT proof the bulk is over: screen-bulk.js picks its
	 * panel from the startup data of that request, so a reload served while
	 * finishBulk is still clearing the queues lands on process/summary/
	 * finished and switches away from the server-rendered dashboard (CI
	 * flake in Chromium and WebKit, 2026-09-16). So: wait for the SERVER to
	 * report the queues clear — which is what this test is really about —
	 * and only then assert the panel on a fresh load.
	 *
	 * WHICH panel is correct depends on the server state, and clearing the
	 * queues is not enough to pin it down. screen-bulk.js:44 picks
	 * "finished" whenever `is_finished && done > 0`, and only falls through
	 * to "dashboard" when nothing was completed. Whether any item finishes
	 * before the stop click lands is a race: locally nothing does, on a
	 * slower CI runner one does — which is what failed Firefox in CI on
	 * 2026-09-21 ("dashboard must be the active, visible panel"). Showing
	 * the finished summary after a partially-completed run is correct
	 * product behaviour, so this asserts the panel the server state
	 * entails, and returns it so the caller can continue deterministically.
	 *
	 * @return The panel SPIO legitimately landed on.
	 */
	async stop(spio: SpioSupport): Promise<'dashboard' | 'finished'> {
		this.page.once('dialog', (dialog) => dialog.accept());
		await withSelfReload(this.page,() => this.page.locator('[data-action="StopBulk"]').click());

		await expect
			.poll(
				async () => {
					const { media, custom } = await spio.bulkStatus();
					const clear = (s: typeof media.stats) =>
						!s.is_preparing && !s.is_running && Number(s.in_queue) === 0 && Number(s.in_process) === 0;
					return clear(media.stats) && clear(custom.stats);
				},
				{ timeout: 30_000, message: 'finishBulk must clear both queues server-side' },
			)
			.toBe(true);

		// Read the terminal state the client will render from.
		const { media, custom } = await spio.bulkStatus();
		const done = Number(media.stats.done) + Number(custom.stats.done);
		const expected: 'dashboard' | 'finished' = done > 0 ? 'finished' : 'dashboard';

		await this.goto();
		await this.expectPanel(expected);
		return expected;
	}

	/** finished → dashboard (server finishBulk + the same self-reload). */
	async finish(): Promise<void> {
		await withSelfReload(this.page,() => this.page.locator('#FinishBulkButton').click());
		await this.expectPanel('dashboard');
	}

	/** Reveal the media error box on the active panel and return its rows. */
	async showErrors(): Promise<Locator> {
		const toggle = this.page.locator('section.panel.active input[name="show-errors"][data-errorbox="media"]');
		await setChecked(toggle, true); // hidden custom switch, data-event="change"
		return this.page.locator('section.panel.active .errorbox.media > div');
	}
}
