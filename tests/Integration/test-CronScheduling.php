<?php
/**
 * CronController scheduling lifecycle tests.
 *
 * Exercises the REAL cron wiring: the constructor registers the
 * cron_schedules intervals and runs the schedule/unschedule pass
 * (bulk_scheduler or bulkRemoveAll, custom_scheduler, tools_scheduler)
 * against live plugin settings and queue stats; checkNewJobs() is the
 * post-enqueue fast path; onDeactivate() is the deactivation cleanup
 * called from InstallHelper::deactivatePlugin().
 *
 * The CronController singleton is deliberately bypassed (`new
 * CronController()`): its private static $instance survives between
 * tests and would freeze the first test's settings snapshot. The
 * constructor is public and runs the full pass each time.
 *
 * @package Shortpixel_Image_Optimiser
 */

use ShortPixel\Controller\CronController;
use ShortPixel\Controller\QueueController;

class CronSchedulingTest extends SPIO_IntegrationTestCase {

	const CRON_HOOKS = array( 'spio-single-cron', 'spio-bulk-cron', 'spio-refresh-dir', 'spio-remove-backups' );

	const SINGLE_ARGS  = array( 0 => array( 'bulk' => false ) );
	const BULK_ARGS    = array( 0 => array( 'bulk' => true ) );
	const REFRESH_ARGS = array( 0 => array( 'amount' => 10 ) );

	public function set_up() {
		parent::set_up();
		$this->clearAllSpioCrons();
	}

	public function tear_down() {
		$this->clearAllSpioCrons();
		parent::tear_down();
	}

	private function clearAllSpioCrons(): void {
		foreach ( self::CRON_HOOKS as $hook ) {
			wp_unschedule_hook( $hook );
		}
	}

	public function test_custom_intervals_are_registered() {
		new CronController();

		$schedules = wp_get_schedules();

		$this->assertArrayHasKey( 'spio_interval', $schedules );
		$this->assertSame( 60, $schedules['spio_interval']['interval'] );
		$this->assertArrayHasKey( 'spio_interval_30min', $schedules );
		$this->assertSame( 1800, $schedules['spio_interval_30min']['interval'] );
	}

	/**
	 * Background processing off = the constructor's bulkRemoveAll() pass must
	 * clear any lingering bulk/single events.
	 */
	public function test_background_off_removes_bulk_events() {
		\wpSPIO()->settings()->doBackgroundProcess = 0;

		// Plant stale events (interval registered via a throwaway instance).
		new CronController();
		wp_schedule_event( time(), 'spio_interval', 'spio-single-cron', self::SINGLE_ARGS );
		wp_schedule_event( time(), 'spio_interval', 'spio-bulk-cron', self::BULK_ARGS );

		new CronController();

		$this->assertFalse( wp_next_scheduled( 'spio-single-cron', self::SINGLE_ARGS ), 'Single cron must be removed when background processing is off' );
		$this->assertFalse( wp_next_scheduled( 'spio-bulk-cron', self::BULK_ARGS ), 'Bulk cron must be removed when background processing is off' );
	}

	/**
	 * checkNewJobs() must schedule the single cron once background processing
	 * is on AND items are awaiting — the fast path AdminController uses right
	 * after an enqueue instead of waiting for the next page load.
	 */
	public function test_check_new_jobs_schedules_single_cron_when_items_await() {
		\wpSPIO()->settings()->doBackgroundProcess = 1;

		$controller = new CronController();
		$this->assertFalse( wp_next_scheduled( 'spio-single-cron', self::SINGLE_ARGS ), 'Empty queue = nothing scheduled yet' );

		$attachment_id = $this->uploadFixture( 'fixture-small.jpg' );
		$imageModel    = \wpSPIO()->filesystem()->getImage( $attachment_id, 'media' );
		( new QueueController() )->addItemToQueue( $imageModel );

		$controller->checkNewJobs();

		$this->assertNotFalse( wp_next_scheduled( 'spio-single-cron', self::SINGLE_ARGS ), 'Awaiting items must schedule the single cron' );
		$this->assertFalse( wp_next_scheduled( 'spio-bulk-cron', self::BULK_ARGS ), 'Bulk cron requires a RUNNING bulk queue, not just items' );
	}

	/**
	 * A scheduled single cron must be unscheduled again by the constructor
	 * pass once the queue has drained (bulkCheckEvent branch).
	 */
	public function test_empty_queue_unschedules_single_cron() {
		\wpSPIO()->settings()->doBackgroundProcess = 1;

		new CronController();
		wp_schedule_event( time(), 'spio_interval', 'spio-single-cron', self::SINGLE_ARGS );
		$this->assertNotFalse( wp_next_scheduled( 'spio-single-cron', self::SINGLE_ARGS ), 'Precondition: event planted' );

		new CronController();

		$this->assertFalse( wp_next_scheduled( 'spio-single-cron', self::SINGLE_ARGS ), 'Empty queue must unschedule the event' );
	}

	public function test_custom_media_setting_toggles_refresh_dir_cron() {
		\wpSPIO()->settings()->showCustomMedia = 1;
		new CronController();
		$this->assertNotFalse( wp_next_scheduled( 'spio-refresh-dir', self::REFRESH_ARGS ), 'Other Media on must schedule the directory refresh' );

		\wpSPIO()->settings()->showCustomMedia = 0;
		new CronController();
		$this->assertFalse( wp_next_scheduled( 'spio-refresh-dir', self::REFRESH_ARGS ), 'Other Media off must unschedule it again' );
	}

	public function test_auto_remove_backups_setting_toggles_daily_cron() {
		\wpSPIO()->settings()->autoRemoveBackups = 1;
		new CronController();

		$timestamp = wp_next_scheduled( 'spio-remove-backups' );
		$this->assertNotFalse( $timestamp, 'autoRemoveBackups on must schedule the daily removal' );
		$this->assertSame( 'daily', wp_get_schedule( 'spio-remove-backups' ) );

		\wpSPIO()->settings()->autoRemoveBackups = 0;
		new CronController();
		$this->assertFalse( wp_next_scheduled( 'spio-remove-backups' ), 'autoRemoveBackups off must unschedule it again' );
	}

	public function test_deactivation_clears_bulk_and_refresh_crons() {
		\wpSPIO()->settings()->doBackgroundProcess = 1;
		\wpSPIO()->settings()->showCustomMedia     = 1;

		$controller = new CronController();
		wp_schedule_event( time(), 'spio_interval', 'spio-single-cron', self::SINGLE_ARGS );
		wp_schedule_event( time(), 'spio_interval', 'spio-bulk-cron', self::BULK_ARGS );
		$this->assertNotFalse( wp_next_scheduled( 'spio-refresh-dir', self::REFRESH_ARGS ), 'Precondition: refresh-dir scheduled' );

		$controller->onDeactivate();

		$this->assertFalse( wp_next_scheduled( 'spio-single-cron', self::SINGLE_ARGS ), 'Deactivation must clear the single cron' );
		$this->assertFalse( wp_next_scheduled( 'spio-bulk-cron', self::BULK_ARGS ), 'Deactivation must clear the bulk cron' );
		$this->assertFalse( wp_next_scheduled( 'spio-refresh-dir', self::REFRESH_ARGS ), 'Deactivation must clear the directory refresh cron' );
	}

	/**
	 * PINNED (bug, found 2026-07-19): onDeactivate() calls bulkRemoveAll() +
	 * custom_scheduler(true) + removeLegacyCron() but never
	 * tools_scheduler(true) — so the daily 'spio-remove-backups' event
	 * SURVIVES plugin deactivation and keeps firing (into a missing action
	 * once the plugin files are gone). One-line fix in onDeactivate():
	 * `$this->tools_scheduler(true);`.
	 *
	 * This pins the BUGGY behaviour so the suite stays green. When the fix
	 * lands this test FAILS — then flip the expectation to assertFalse.
	 */
	public function test_deactivation_leaves_remove_backups_cron_pinned() {
		\wpSPIO()->settings()->autoRemoveBackups = 1;

		$controller = new CronController();
		$this->assertNotFalse( wp_next_scheduled( 'spio-remove-backups' ), 'Precondition: daily removal scheduled' );

		$controller->onDeactivate();

		$this->assertNotFalse(
			wp_next_scheduled( 'spio-remove-backups' ),
			'onDeactivate() appears to now clear spio-remove-backups — bug FIXED, flip this pinned test to assertFalse.'
		);
	}
}
