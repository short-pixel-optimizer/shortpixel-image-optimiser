<?php
/**
 * Tests for ShortPixel\Controller\Backup\LocalBackupController.
 *
 * Scope:
 *   - Inheritance contract: is-a BackupController; is-a LocalBackupController.
 *   - Factory selection: getBackupController() returns LocalBackupController
 *     exactly when backupImages is truthy.
 *   - checkRemoveBackups() inherited gating with each valid period string.
 *   - cronRemoveBackups() / cliRemoveBackups() gate return values — dispatch
 *     path asserted via a subclass spy because autoRemoveBackups() calls the
 *     real filesystem controller.
 *   - getPeriodAr() (private) — date arithmetic produces correct structure
 *     for every supported period token; unknown token returns null.
 *
 * Out of scope (integration territory — see integration-test punch list):
 *   - autoRemoveBackups() full walk (calls filesystem controller, DirectoryModel
 *     traversal, real file/directory deletion).
 *   - checkFilesinDirectory() (DirectoryModel::getFiles() + FileModel::delete()
 *     on a real backup tree).
 *   - getBackupBaseDirectory() (depends on wpSPIO()->filesystem()->getWPUploadBase()).
 *   - createBackupFile / restore / hasBackup / renameBackup (LocalBackupModel).
 *
 * @package Shortpixel_Image_Optimiser
 */

use ShortPixel\Controller\Backup\BackupController;
use ShortPixel\Controller\Backup\LocalBackupController;

/**
 * Spy subclass: overrides autoRemoveBackups() so we can assert it is called
 * without touching the real filesystem.
 */
class SPIO_SpyLocalBackupController extends LocalBackupController {
	public $autoRemoveCalled = false;

	protected function autoRemoveBackups() {
		$this->autoRemoveCalled = true;
	}
}

class LocalBackupControllerTest extends WP_UnitTestCase {

	// -----------------------------------------------------------------------
	// Helpers
	// -----------------------------------------------------------------------

	/**
	 * Reset BackupController static state so factory tests are order-independent.
	 */
	private function resetStaticState(): void {
		$ref = new ReflectionClass( BackupController::class );

		foreach ( array( 'instance', 'models', 'model' ) as $prop ) {
			$p = $ref->getProperty( $prop );
			$p->setAccessible( true );
			$p->setValue( null, 'models' === $prop ? array() : null );
		}
	}

	/**
	 * Save/restore backupImages around a test body and reset statics.
	 */
	private function withBackupEnabled( callable $fn ): void {
		$settings = \wpSPIO()->settings();
		$prev     = $settings->backupImages;
		$settings->backupImages = true;
		try {
			$fn();
		} finally {
			$settings->backupImages = $prev;
			$this->resetStaticState();
		}
	}

	/**
	 * Save/restore auto-remove settings around a test body.
	 */
	private function withRemoveSettings( bool $enabled, $period, callable $fn ): void {
		$settings = \wpSPIO()->settings();

		// SettingsModel::__set() sanitizes string fields, turning null into ''
		// — making checkRemoveBackups()'s is_null() branch unreachable via the
		// setter. Unset the raw key instead so __get() falls back to the model
		// default (null). Snapshot/restore the raw array around the test body.
		$r = new ReflectionClass( \ShortPixel\Model\SettingsModel::class );
		while ( $r && ! $r->hasProperty( 'settings' ) ) {
			$r = $r->getParentClass();
		}
		$prop = $r->getProperty( 'settings' );
		$prop->setAccessible( true );
		$raw = $prop->getValue( $settings );

		$settings->autoRemoveBackups = $enabled;
		if ( null === $period ) {
			$current = $prop->getValue( $settings );
			unset( $current['autoRemoveBackupsPeriod'] );
			$prop->setValue( $settings, $current );
		} else {
			$settings->autoRemoveBackupsPeriod = $period;
		}

		try {
			$fn();
		} finally {
			$prop->setValue( $settings, $raw );
		}
	}

	/**
	 * Invoke a private method on a LocalBackupController (or subclass) instance.
	 */
	private function invokePrivate( LocalBackupController $ctrl, string $method, array $args = array() ) {
		$ref = new ReflectionClass( LocalBackupController::class );
		while ( $ref && ! $ref->hasMethod( $method ) ) {
			$ref = $ref->getParentClass();
		}
		$m = $ref->getMethod( $method );
		$m->setAccessible( true );
		return $m->invoke( $ctrl, ...$args );
	}

	// -----------------------------------------------------------------------
	// set_up / tear_down
	// -----------------------------------------------------------------------

	public function set_up(): void {
		parent::set_up();
		$this->resetStaticState();
	}

	public function tear_down(): void {
		$this->resetStaticState();
		parent::tear_down();
	}

	// -----------------------------------------------------------------------
	// Inheritance / class hierarchy
	// -----------------------------------------------------------------------

	/*
	 * Inheritance — LocalBackupController is a BackupController
	 */

	public function test_extends_BackupController() {
		$this->assertTrue( is_subclass_of( LocalBackupController::class, BackupController::class ) );
	}

	public function test_is_instantiable_via_public_constructor() {
		$ctrl = new LocalBackupController();
		$this->assertInstanceOf( LocalBackupController::class, $ctrl );
	}

	// -----------------------------------------------------------------------
	// Factory selection
	// -----------------------------------------------------------------------

	public function test_getBackupController_returns_LocalBackupController_when_backupImages_true() {
		$this->withBackupEnabled( function () {
			$ctrl = BackupController::getBackupController();
			$this->assertInstanceOf( LocalBackupController::class, $ctrl );
		} );
	}

	// -----------------------------------------------------------------------
	// cronRemoveBackups / cliRemoveBackups — gate + dispatch via spy
	// -----------------------------------------------------------------------

	/*
	 * cronRemoveBackups — gate blocks when conditions not met
	 */

	public function test_cronRemoveBackups_returns_false_when_autoRemoveBackups_off() {
		$spy = new SPIO_SpyLocalBackupController();

		$this->withRemoveSettings( false, '1year', function () use ( $spy ) {
			$result = $spy->cronRemoveBackups();
			$this->assertFalse( $result );
			$this->assertFalse( $spy->autoRemoveCalled );
		} );
	}

	public function test_cronRemoveBackups_returns_false_when_period_is_null() {
		$spy = new SPIO_SpyLocalBackupController();

		$this->withRemoveSettings( true, null, function () use ( $spy ) {
			$result = $spy->cronRemoveBackups();
			$this->assertFalse( $result );
			$this->assertFalse( $spy->autoRemoveCalled );
		} );
	}

	public function test_cronRemoveBackups_calls_autoRemoveBackups_when_conditions_met() {
		$spy = new SPIO_SpyLocalBackupController();

		$this->withRemoveSettings( true, '1year', function () use ( $spy ) {
			$spy->cronRemoveBackups();
			$this->assertTrue( $spy->autoRemoveCalled );
		} );
	}

	/*
	 * cliRemoveBackups — identical gate logic, separate entry point
	 */

	public function test_cliRemoveBackups_returns_false_when_autoRemoveBackups_off() {
		$spy = new SPIO_SpyLocalBackupController();

		$this->withRemoveSettings( false, '1year', function () use ( $spy ) {
			$result = $spy->cliRemoveBackups();
			$this->assertFalse( $result );
			$this->assertFalse( $spy->autoRemoveCalled );
		} );
	}

	public function test_cliRemoveBackups_calls_autoRemoveBackups_when_conditions_met() {
		$spy = new SPIO_SpyLocalBackupController();

		$this->withRemoveSettings( true, '6month', function () use ( $spy ) {
			$spy->cliRemoveBackups();
			$this->assertTrue( $spy->autoRemoveCalled );
		} );
	}

	// -----------------------------------------------------------------------
	// getPeriodAr() — private date-arithmetic helper
	// -----------------------------------------------------------------------

	/*
	 * getPeriodAr — null for unknown period token
	 */

	public function test_getPeriodAr_returns_null_for_unknown_period() {
		$ctrl = new LocalBackupController();

		$this->withRemoveSettings( true, 'fortnight', function () use ( $ctrl ) {
			$result = $this->invokePrivate( $ctrl, 'getPeriodAr' );
			$this->assertNull( $result );
		} );
	}

	public function test_getPeriodAr_returns_null_for_empty_string_period() {
		$ctrl = new LocalBackupController();

		$this->withRemoveSettings( true, '', function () use ( $ctrl ) {
			$result = $this->invokePrivate( $ctrl, 'getPeriodAr' );
			$this->assertNull( $result );
		} );
	}

	/*
	 * getPeriodAr — valid period tokens produce correct array shape
	 */

	public function test_getPeriodAr_returns_array_with_required_keys_for_month() {
		$ctrl = new LocalBackupController();

		$this->withRemoveSettings( true, 'month', function () use ( $ctrl ) {
			$result = $this->invokePrivate( $ctrl, 'getPeriodAr' );
			$this->assertIsArray( $result );
			$this->assertArrayHasKey( 'month', $result );
			$this->assertArrayHasKey( 'year', $result );
			$this->assertArrayHasKey( 'date', $result );
		} );
	}

	public function test_getPeriodAr_month_field_is_zero_padded_two_digits() {
		$ctrl = new LocalBackupController();

		foreach ( array( 'month', '3month', '6month', '1year', '2year', '5year' ) as $period ) {
			$this->withRemoveSettings( true, $period, function () use ( $ctrl, $period ) {
				$result = $this->invokePrivate( $ctrl, 'getPeriodAr' );
				$this->assertIsArray( $result, "Period '$period' should return array." );
				$this->assertMatchesRegularExpression(
					'/^\d{2}$/',
					$result['month'],
					"month field for '$period' should be two-digit zero-padded."
				);
			} );
		}
	}

	public function test_getPeriodAr_year_field_is_four_digit_string() {
		$ctrl = new LocalBackupController();

		foreach ( array( 'month', '3month', '6month', '1year', '2year', '5year' ) as $period ) {
			$this->withRemoveSettings( true, $period, function () use ( $ctrl, $period ) {
				$result = $this->invokePrivate( $ctrl, 'getPeriodAr' );
				$this->assertIsArray( $result, "Period '$period' should return array." );
				$this->assertMatchesRegularExpression(
					'/^\d{4}$/',
					$result['year'],
					"year field for '$period' should be four-digit string."
				);
			} );
		}
	}

	public function test_getPeriodAr_date_field_is_integer_timestamp_in_the_past() {
		$ctrl = new LocalBackupController();

		$this->withRemoveSettings( true, '1year', function () use ( $ctrl ) {
			$result = $this->invokePrivate( $ctrl, 'getPeriodAr' );
			$this->assertIsArray( $result );
			$this->assertIsInt( $result['date'] );
			// The cutoff must be strictly before now.
			$this->assertLessThan( time(), $result['date'] );
		} );
	}

	/*
	 * getPeriodAr — period offsets are applied correctly
	 *
	 * Strategy: compute the result and verify the timestamp falls in a window
	 * that is consistent with subtracting the expected interval from now.
	 * We allow a ±90-second tolerance to absorb test-process clock drift.
	 */

	public function test_getPeriodAr_month_cutoff_is_approximately_one_month_ago() {
		$ctrl    = new LocalBackupController();
		$tolerance = 90; // seconds

		$this->withRemoveSettings( true, 'month', function () use ( $ctrl, $tolerance ) {
			$result    = $this->invokePrivate( $ctrl, 'getPeriodAr' );
			$expected  = ( new \DateTime() )->sub( new \DateInterval( 'P1M' ) )->getTimestamp();
			// The timestamp must be within tolerance of "now minus 1 month".
			$this->assertEqualsWithDelta( $expected, $result['date'], $tolerance );
		} );
	}

	public function test_getPeriodAr_1year_cutoff_is_approximately_one_year_ago() {
		$ctrl      = new LocalBackupController();
		$tolerance = 90;

		$this->withRemoveSettings( true, '1year', function () use ( $ctrl, $tolerance ) {
			$result   = $this->invokePrivate( $ctrl, 'getPeriodAr' );
			$expected = ( new \DateTime() )->sub( new \DateInterval( 'P1Y' ) )->getTimestamp();
			$this->assertEqualsWithDelta( $expected, $result['date'], $tolerance );
		} );
	}

	/*
	 * getPeriodAr — month/year directory components include the extra -1month buffer
	 *
	 * The design adds an extra month subtraction to the directory components
	 * (but NOT to the timestamp) to avoid deleting the boundary month's directory.
	 * So for a '1year' period, the year/month reflect "now - 1year - 1month".
	 */

	public function test_getPeriodAr_month_year_lag_one_month_behind_timestamp() {
		$ctrl      = new LocalBackupController();
		$tolerance = 90;

		$this->withRemoveSettings( true, '1year', function () use ( $ctrl, $tolerance ) {
			$result = $this->invokePrivate( $ctrl, 'getPeriodAr' );

			// Build the expected directory cutoff: now - 1year - 1month.
			$dirDate = ( new \DateTime() )
				->sub( new \DateInterval( 'P1Y' ) )
				->sub( new \DateInterval( 'P1M' ) );

			$this->assertSame( $dirDate->format( 'm' ), $result['month'] );
			$this->assertSame( $dirDate->format( 'Y' ), $result['year'] );
		} );
	}

}
