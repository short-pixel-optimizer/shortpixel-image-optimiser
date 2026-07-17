<?php
/**
 * Tests for ShortPixel\Controller\CronController.
 *
 * Covers:
 *   - cron_schedules filter — verifies that the two custom intervals
 *     (spio_interval, spio_interval_30min) are added with the expected
 *     keys and positive integer interval values.
 *   - getInstance() singleton contract.
 *   - custom_scheduler (protected) — confirms that spio-refresh-dir is
 *     scheduled when Other Media is enabled and unscheduled when disabled,
 *     exercising wp_next_scheduled against the real WP test cron store.
 *   - tools_scheduler (protected) — same pattern for spio-remove-backups.
 *   - bulkRemoveAll (protected) — clears scheduled bulk events when they
 *     exist.
 *
 * Out of scope (and why):
 *   - __construct full path — calls checkActive() → wpSPIO()->settings(), then
 *     bulk_scheduler() → QueueController → queue DB tables, and init() →
 *     AdminController::getInstance() / BackupController::getBackupController().
 *     Instantiating CronController in the test harness would trigger all of
 *     these side effects; controlled via reflection instead.
 *   - bulkScheduleEvent / bulkCheckEvent — delegate to getQueueData() which
 *     creates a live QueueController; out of scope for unit tests.
 *   - checkNewJobs / onDeactivate — thin orchestrators of the above; also
 *     out of scope.
 *
 * @package Shortpixel_Image_Optimiser
 */

use ShortPixel\Controller\CronController;

class CronControllerTest extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		$this->resetSingleton();
		// Start each test with a clean cron slate for the plugin hooks.
		$this->unscheduleAllPluginEvents();
	}

	public function tear_down() {
		$this->unscheduleAllPluginEvents();
		$this->resetSingleton();
		parent::tear_down();
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	private function resetSingleton(): void {
		$ref = new ReflectionClass( CronController::class );
		$p   = $ref->getProperty( 'instance' );
		$p->setAccessible( true );
		$p->setValue( null, null );
	}

	/**
	 * Return a CronController instance constructed WITHOUT calling the
	 * real constructor (which has heavy side-effects).
	 */
	private function freshController(): CronController {
		$ref = new ReflectionClass( CronController::class );
		$ctrl = $ref->newInstanceWithoutConstructor();

		// Populate cron_options so methods that iterate it work correctly.
		$p = $ref->getProperty( 'cron_options' );
		$p->setAccessible( true );
		$p->setValue( $ctrl, array(
			'single' => array( 'cron_name' => 'spio-single-cron', 'bulk' => false ),
			'bulk'   => array( 'cron_name' => 'spio-bulk-cron',   'bulk' => true ),
		) );

		return $ctrl;
	}

	private function invokeProtected( CronController $obj, string $method, array $args = array() ) {
		$ref = new ReflectionClass( CronController::class );
		$m   = $ref->getMethod( $method );
		$m->setAccessible( true );
		return $m->invoke( $obj, ...$args );
	}

	private function setProtected( CronController $obj, string $prop, $value ): void {
		$ref = new ReflectionClass( CronController::class );
		$p   = $ref->getProperty( $prop );
		$p->setAccessible( true );
		$p->setValue( $obj, $value );
	}

	/** Clear all plugin-specific WP cron events so tests start from a known state.
	 *
	 * Must use wp_unschedule_hook(), which removes ALL events for a hook
	 * regardless of their args. wp_clear_scheduled_hook($hook) and
	 * wp_next_scheduled($hook) only match events registered with an EMPTY args
	 * array — but the plugin's own init (run once at bootstrap, committed to
	 * the DB before test transactions start, and restored by every rollback)
	 * registers spio-refresh-dir WITH args [0 => ['amount' => 10]]. That event
	 * silently survived cleanup and leaked into every test.
	 */
	private function unscheduleAllPluginEvents(): void {
		$hookNames = array(
			'spio-single-cron',
			'spio-bulk-cron',
			'spio-refresh-dir',
			'spio-remove-backups',
		);
		foreach ( $hookNames as $hook ) {
			wp_unschedule_hook( $hook );
		}
	}

	// -------------------------------------------------------------------------
	// cron_schedules filter — custom intervals
	// -------------------------------------------------------------------------

	public function test_cron_schedules_adds_spio_interval_key() {
		$ctrl   = $this->freshController();
		$result = $ctrl->cron_schedules( array() );

		$this->assertArrayHasKey( 'spio_interval', $result );
	}

	public function test_cron_schedules_adds_spio_interval_30min_key() {
		$ctrl   = $this->freshController();
		$result = $ctrl->cron_schedules( array() );

		$this->assertArrayHasKey( 'spio_interval_30min', $result );
	}

	public function test_cron_schedules_spio_interval_has_positive_integer_interval() {
		$ctrl   = $this->freshController();
		$result = $ctrl->cron_schedules( array() );

		$this->assertIsInt( $result['spio_interval']['interval'] );
		$this->assertGreaterThan( 0, $result['spio_interval']['interval'] );
	}

	public function test_cron_schedules_spio_interval_30min_has_positive_integer_interval() {
		$ctrl   = $this->freshController();
		$result = $ctrl->cron_schedules( array() );

		$this->assertIsInt( $result['spio_interval_30min']['interval'] );
		$this->assertGreaterThan( 0, $result['spio_interval_30min']['interval'] );
	}

	public function test_cron_schedules_preserves_existing_entries() {
		$ctrl     = $this->freshController();
		$incoming = array( 'twicedaily' => array( 'interval' => 43200, 'display' => 'Twice Daily' ) );
		$result   = $ctrl->cron_schedules( $incoming );

		$this->assertArrayHasKey( 'twicedaily', $result );
	}

	public function test_cron_schedules_each_entry_has_a_display_string() {
		$ctrl   = $this->freshController();
		$result = $ctrl->cron_schedules( array() );

		$this->assertIsString( $result['spio_interval']['display'] );
		$this->assertNotEmpty( $result['spio_interval']['display'] );

		$this->assertIsString( $result['spio_interval_30min']['display'] );
		$this->assertNotEmpty( $result['spio_interval_30min']['display'] );
	}

	public function test_cron_schedules_interval_is_filterable() {
		$ctrl = $this->freshController();

		// Override the filter to return a custom value.
		add_filter( 'shortpixel/cron/interval', function() { return 120; } );
		$result = $ctrl->cron_schedules( array() );
		remove_all_filters( 'shortpixel/cron/interval' );

		$this->assertSame( 120, $result['spio_interval']['interval'] );
	}

	// -------------------------------------------------------------------------
	// getInstance — singleton contract
	// -------------------------------------------------------------------------

	/**
	 * Note: calling getInstance() triggers the full constructor which requires
	 * settings and queue infrastructure.  We only verify the singleton identity
	 * contract using two getinstance() calls on the same already-constructed
	 * object injected via reflection — avoiding the constructor side-effects.
	 */
	public function test_getInstance_returns_the_same_object_on_repeated_calls() {
		$ref  = new ReflectionClass( CronController::class );
		$prop = $ref->getProperty( 'instance' );
		$prop->setAccessible( true );

		// Seed a stub instance so getInstance() short-circuits.
		$stub = $this->freshController();
		$prop->setValue( null, $stub );

		$a = CronController::getInstance();
		$b = CronController::getInstance();

		$this->assertSame( $a, $b );
		$this->assertSame( $stub, $a );
	}

	// -------------------------------------------------------------------------
	// custom_scheduler (protected) — spio-refresh-dir
	// -------------------------------------------------------------------------

	public function test_custom_scheduler_schedules_refresh_dir_when_other_media_enabled() {
		$ctrl = $this->freshController();

		// Force the filter to say "yes, add the cron".
		add_filter( 'shortpixel/othermedia/add_cron', '__return_true' );

		$this->invokeProtected( $ctrl, 'custom_scheduler', array( false ) );

		remove_all_filters( 'shortpixel/othermedia/add_cron' );

		$args      = array( 0 => array( 'amount' => 10 ) );
		$scheduled = wp_next_scheduled( 'spio-refresh-dir', $args );

		$this->assertNotFalse( $scheduled, 'spio-refresh-dir should be scheduled when Other Media is enabled' );
	}

	public function test_custom_scheduler_does_not_schedule_refresh_dir_when_other_media_disabled() {
		$ctrl = $this->freshController();

		add_filter( 'shortpixel/othermedia/add_cron', '__return_false' );

		$this->invokeProtected( $ctrl, 'custom_scheduler', array( false ) );

		remove_all_filters( 'shortpixel/othermedia/add_cron' );

		$args      = array( 0 => array( 'amount' => 10 ) );
		$scheduled = wp_next_scheduled( 'spio-refresh-dir', $args );

		$this->assertFalse( $scheduled, 'spio-refresh-dir should NOT be scheduled when Other Media is disabled' );
	}

	public function test_custom_scheduler_unschedules_refresh_dir_when_forced_to_unschedule() {
		// Pre-schedule the event.
		$args = array( 0 => array( 'amount' => 10 ) );
		wp_schedule_event( time(), 'hourly', 'spio-refresh-dir', $args );

		$this->assertNotFalse( wp_next_scheduled( 'spio-refresh-dir', $args ), 'precondition: event must be scheduled' );

		$ctrl = $this->freshController();

		// Even if the filter says "add", passing $unschedule=true must win.
		add_filter( 'shortpixel/othermedia/add_cron', '__return_true' );
		$this->invokeProtected( $ctrl, 'custom_scheduler', array( true ) );
		remove_all_filters( 'shortpixel/othermedia/add_cron' );

		$this->assertFalse( wp_next_scheduled( 'spio-refresh-dir', $args ), 'spio-refresh-dir must be removed when $unschedule=true' );
	}

	// -------------------------------------------------------------------------
	// tools_scheduler (protected) — spio-remove-backups
	// -------------------------------------------------------------------------

	public function test_tools_scheduler_schedules_remove_backups_when_setting_is_active() {
		$ctrl = $this->freshController();

		// SettingsModel is a singleton whose settings are cached in-memory after
		// the first load(). Updating the WP option has no effect because the
		// singleton never re-reads it. We must inject the value directly into the
		// singleton's private $settings array via reflection.
		$settings    = \wpSPIO()->settings();
		$settingsRef = new ReflectionClass( get_class( $settings ) );
		// Walk up to the class that declares the $settings property.
		$settingsPropClass = $settingsRef;
		while ( ! $settingsPropClass->hasProperty( 'settings' ) ) {
			$settingsPropClass = $settingsPropClass->getParentClass();
		}
		$settingsProp = $settingsPropClass->getProperty( 'settings' );
		$settingsProp->setAccessible( true );
		$originalSettings = $settingsProp->getValue( $settings );

		$injected                    = is_array( $originalSettings ) ? $originalSettings : array();
		$injected['autoRemoveBackups'] = true;
		$settingsProp->setValue( $settings, $injected );

		$this->invokeProtected( $ctrl, 'tools_scheduler', array( false ) );

		// Restore original settings so other tests are not affected.
		$settingsProp->setValue( $settings, $originalSettings );

		$scheduled = wp_next_scheduled( 'spio-remove-backups' );

		$this->assertNotFalse( $scheduled, 'spio-remove-backups should be scheduled when autoRemoveBackups is active' );
	}

	public function test_tools_scheduler_does_not_schedule_remove_backups_when_setting_is_inactive() {
		$ctrl = $this->freshController();

		$current = get_option( 'wp-short-pixel-settings', array() );
		$current['autoRemoveBackups'] = 0;
		update_option( 'wp-short-pixel-settings', $current );

		$this->invokeProtected( $ctrl, 'tools_scheduler', array( false ) );

		$scheduled = wp_next_scheduled( 'spio-remove-backups' );

		$this->assertFalse( $scheduled, 'spio-remove-backups should NOT be scheduled when autoRemoveBackups is off' );
	}

	public function test_tools_scheduler_unschedules_remove_backups_when_forced() {
		// Pre-schedule.
		wp_schedule_event( time(), 'daily', 'spio-remove-backups' );
		$this->assertNotFalse( wp_next_scheduled( 'spio-remove-backups' ), 'precondition: event scheduled' );

		$ctrl = $this->freshController();
		$this->invokeProtected( $ctrl, 'tools_scheduler', array( true ) );

		$this->assertFalse( wp_next_scheduled( 'spio-remove-backups' ), 'spio-remove-backups must be removed when $unschedule=true' );
	}

	// -------------------------------------------------------------------------
	// bulkRemoveAll (protected) — removes existing bulk events
	// -------------------------------------------------------------------------

	public function test_bulkRemoveAll_unschedules_existing_single_and_bulk_events() {
		// Pre-schedule both events with the current argument format.
		$singleArgs = array( 0 => array( 'bulk' => false ) );
		$bulkArgs   = array( 0 => array( 'bulk' => true ) );

		wp_schedule_event( time(), 'hourly', 'spio-single-cron', $singleArgs );
		wp_schedule_event( time(), 'hourly', 'spio-bulk-cron',   $bulkArgs );

		$this->assertNotFalse( wp_next_scheduled( 'spio-single-cron', $singleArgs ), 'precondition' );
		$this->assertNotFalse( wp_next_scheduled( 'spio-bulk-cron',   $bulkArgs ),   'precondition' );

		$ctrl = $this->freshController();
		$this->invokeProtected( $ctrl, 'bulkRemoveAll' );

		$this->assertFalse( wp_next_scheduled( 'spio-single-cron', $singleArgs ), 'spio-single-cron should be unscheduled' );
		$this->assertFalse( wp_next_scheduled( 'spio-bulk-cron',   $bulkArgs ),   'spio-bulk-cron should be unscheduled' );
	}

	public function test_bulkRemoveAll_is_safe_when_no_events_are_scheduled() {
		$ctrl = $this->freshController();

		// Should not throw; all events are already absent.
		$this->invokeProtected( $ctrl, 'bulkRemoveAll' );

		$this->assertFalse( wp_next_scheduled( 'spio-single-cron', array( 0 => array( 'bulk' => false ) ) ) );
		$this->assertFalse( wp_next_scheduled( 'spio-bulk-cron',   array( 0 => array( 'bulk' => true ) ) ) );
	}

} // class
