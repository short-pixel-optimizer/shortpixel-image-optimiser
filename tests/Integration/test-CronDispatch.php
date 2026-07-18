<?php
/**
 * Integration tests: real cron-driven queue dispatch (Wave 3).
 *
 * Earlier suites loop-tick the queue directly (Wave-1 decision). This suite
 * covers the production background path instead: CronController registering
 * schedules + events, enqueueing scheduling a `spio-single-cron` event, and
 * that event — dispatched the way WP cron dispatches it, via
 * `do_action_ref_array()` with the stored event args — driving
 * AdminController::processCronHook() through a full optimization.
 *
 * True `wp-cron.php` HTTP spawning is not possible in the WP test-lib (no
 * web server), so dispatch fidelity ends at the do_action boundary — the
 * same boundary WP core's `wp_cron()` hands off at.
 *
 * @package Shortpixel_Image_Optimiser
 */

use ShortPixel\Controller\CronController;
use ShortPixel\Controller\QueueController;

class CronDispatchTest extends SPIO_IntegrationTestCase {

	private const SINGLE_ARGS = array( 0 => array( 'bulk' => false ) );
	private const BULK_ARGS   = array( 0 => array( 'bulk' => true ) );

	public function set_up() {
		parent::set_up();

		// processCronHook defaults to max_runs=10 / wait=1s — each round
		// would sleep for real while ShortQ's 10s retry gate holds items
		// back. Cap every dispatch at ONE queue pass (a production cron
		// firing 60s apart advances the queue one pass at a time anyway)
		// and drop the sleep; the test backdates items between dispatches.
		add_filter(
			'shortpixel/process_hook/options',
			function ( $args ) {
				$args['max_runs'] = 1;
				$args['wait']     = 0;
				return $args;
			}
		);

		$this->resetCronController();
	}

	public function tear_down() {
		$this->resetCronController();
		parent::tear_down();
	}

	// -------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------

	/**
	 * Drop the CronController singleton so the next getInstance() re-reads
	 * settings and re-runs the constructor's schedule/unschedule pass.
	 */
	private function resetCronController(): void {
		$ref = new ReflectionClass( CronController::class );
		if ( $ref->hasProperty( 'instance' ) ) {
			$prop = $ref->getProperty( 'instance' );
			$prop->setAccessible( true );
			$prop->setValue( null, null );
		}
	}

	/** Fresh CronController — constructor runs the scheduler pass. */
	private function freshCronController(): CronController {
		$this->resetCronController();
		return CronController::getInstance();
	}

	/**
	 * Dispatch due spio-* cron events exactly as WP core's wp_cron() would:
	 * events whose timestamp has passed fire via do_action_ref_array() with
	 * their stored args.
	 *
	 * @return int Number of events dispatched.
	 */
	private function dispatchDueSpioCronEvents(): int {
		$dispatched = 0;
		$crons      = _get_cron_array();
		if ( ! is_array( $crons ) ) {
			return 0;
		}
		foreach ( $crons as $timestamp => $hooks ) {
			if ( $timestamp > time() ) {
				continue;
			}
			foreach ( $hooks as $hook => $events ) {
				if ( 0 !== strpos( $hook, 'spio-' ) ) {
					continue;
				}
				foreach ( $events as $event ) {
					do_action_ref_array( $hook, $event['args'] );
					$dispatched++;
				}
			}
		}
		return $dispatched;
	}

	/** Reload a fresh image model straight from the DB (no cached state). */
	private function freshImageModel( int $attachment_id ) {
		return \wpSPIO()->filesystem()->getImage( $attachment_id, 'media', false );
	}

	// -------------------------------------------------------------------
	// Schedules + hook bindings
	// -------------------------------------------------------------------

	public function test_spio_cron_schedules_are_registered() {
		$this->freshCronController();

		$schedules = wp_get_schedules();

		$this->assertArrayHasKey( 'spio_interval', $schedules );
		$this->assertSame( 60, $schedules['spio_interval']['interval'] );

		$this->assertArrayHasKey( 'spio_interval_30min', $schedules );
		$this->assertSame( 30 * MINUTE_IN_SECONDS, $schedules['spio_interval_30min']['interval'] );
	}

	public function test_cron_actions_are_bound() {
		$this->freshCronController();

		foreach ( array( 'spio-single-cron', 'spio-bulk-cron', 'spio-refresh-dir', 'spio-remove-backups' ) as $hook ) {
			$this->assertNotFalse(
				has_action( $hook ),
				"Cron hook '$hook' must have a bound listener — a scheduled event with no listener is a silent no-op."
			);
		}
	}

	// -------------------------------------------------------------------
	// Event scheduling driven by enqueueing
	// -------------------------------------------------------------------

	public function test_enqueue_with_background_processing_schedules_single_cron_event() {
		\wpSPIO()->settings()->doBackgroundProcess = 1;
		$this->resetCronController();

		$id = $this->uploadFixture( 'fixture-small.jpg' );

		$imageModel      = \wpSPIO()->filesystem()->getImage( $id, 'media' );
		$queueController = new QueueController();
		$queueController->addItemToQueue( $imageModel );

		$timestamp = wp_next_scheduled( 'spio-single-cron', self::SINGLE_ARGS );
		$this->assertNotFalse( $timestamp, 'Enqueueing with background processing on must schedule spio-single-cron.' );

		$event = wp_get_scheduled_event( 'spio-single-cron', self::SINGLE_ARGS );
		$this->assertSame( 'spio_interval', $event->schedule, 'Single cron must recur on the 60s spio_interval schedule.' );

		// The bulk event only appears when a bulk run is active.
		$this->assertFalse(
			wp_next_scheduled( 'spio-bulk-cron', self::BULK_ARGS ),
			'spio-bulk-cron must not be scheduled when no bulk is running.'
		);
	}

	public function test_enqueue_with_background_processing_off_schedules_nothing() {
		\wpSPIO()->settings()->doBackgroundProcess = 0;
		$this->resetCronController();

		$id = $this->uploadFixture( 'fixture-small.jpg' );

		$imageModel      = \wpSPIO()->filesystem()->getImage( $id, 'media' );
		$queueController = new QueueController();
		$queueController->addItemToQueue( $imageModel );

		$this->assertFalse(
			wp_next_scheduled( 'spio-single-cron', self::SINGLE_ARGS ),
			'Without doBackgroundProcess no cron event may be scheduled.'
		);
	}

	public function test_background_processing_disabled_removes_stale_events() {
		wp_schedule_event( time(), 'spio_interval', 'spio-single-cron', self::SINGLE_ARGS );
		$this->assertNotFalse( wp_next_scheduled( 'spio-single-cron', self::SINGLE_ARGS ) );

		\wpSPIO()->settings()->doBackgroundProcess = 0;
		$this->freshCronController();

		$this->assertFalse(
			wp_next_scheduled( 'spio-single-cron', self::SINGLE_ARGS ),
			'CronController must clean up scheduled events when background processing is off.'
		);
	}

	// -------------------------------------------------------------------
	// Real dispatch: due event → processCronHook → optimized image
	// -------------------------------------------------------------------

	public function test_due_single_cron_event_optimizes_enqueued_image_end_to_end() {
		\wpSPIO()->settings()->doBackgroundProcess = 1;
		$this->resetCronController();

		$id = $this->uploadFixture( 'fixture-small.jpg' );

		$imageModel      = \wpSPIO()->filesystem()->getImage( $id, 'media' );
		$queueController = new QueueController();
		$queueController->addItemToQueue( $imageModel );

		$this->assertNotFalse(
			wp_next_scheduled( 'spio-single-cron', self::SINGLE_ARGS ),
			'Precondition: enqueueing must schedule the single cron event.'
		);

		// Each dispatch = one cron firing = one queue pass (see set_up
		// filter). The first pass sends the (mocked) reducer request
		// non-blocking; a later pass collects the result. Backdating
		// between dispatches stands in for the 60s between cron firings.
		$optimized = false;
		for ( $firing = 0; $firing < 8; $firing++ ) {
			$this->assertGreaterThan( 0, $this->dispatchDueSpioCronEvents(), 'A due spio cron event must exist to dispatch.' );

			if ( $this->freshImageModel( $id )->isOptimized() ) {
				$optimized = true;
				break;
			}
			$this->backdateQueueItems();
		}

		$this->assertTrue( $optimized, 'The image must end up optimized purely via cron dispatches (no direct queue ticks).' );

		// Queue drained → the next CronController scheduler pass removes
		// the recurring event instead of letting it fire forever.
		$this->freshCronController();
		$this->assertFalse(
			wp_next_scheduled( 'spio-single-cron', self::SINGLE_ARGS ),
			'The single cron event must be unscheduled once the queue is empty.'
		);
	}

	// -------------------------------------------------------------------
	// Auxiliary crons follow their settings
	// -------------------------------------------------------------------

	public function test_remove_backups_cron_follows_autoRemoveBackups_setting() {
		\wpSPIO()->settings()->autoRemoveBackups = 1;
		$this->freshCronController();

		$timestamp = wp_next_scheduled( 'spio-remove-backups' );
		$this->assertNotFalse( $timestamp, 'autoRemoveBackups on must schedule the daily removal cron.' );

		$event = wp_get_scheduled_event( 'spio-remove-backups' );
		$this->assertSame( 'daily', $event->schedule );

		\wpSPIO()->settings()->autoRemoveBackups = 0;
		$this->freshCronController();

		$this->assertFalse(
			wp_next_scheduled( 'spio-remove-backups' ),
			'Turning autoRemoveBackups off must unschedule the removal cron.'
		);
	}

	public function test_refresh_dir_cron_follows_showCustomMedia_setting() {
		$args = array( 0 => array( 'amount' => 10 ) );

		\wpSPIO()->settings()->showCustomMedia = 1;
		$this->freshCronController();

		$timestamp = wp_next_scheduled( 'spio-refresh-dir', $args );
		$this->assertNotFalse( $timestamp, 'showCustomMedia on must schedule the directory-refresh cron.' );

		$event = wp_get_scheduled_event( 'spio-refresh-dir', $args );
		$this->assertSame( 'spio_interval_30min', $event->schedule );

		\wpSPIO()->settings()->showCustomMedia = 0;
		$this->freshCronController();

		$this->assertFalse(
			wp_next_scheduled( 'spio-refresh-dir', $args ),
			'Turning showCustomMedia off must unschedule the directory-refresh cron.'
		);
	}
}
