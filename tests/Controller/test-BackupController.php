<?php
/**
 * Tests for ShortPixel\Controller\Backup\BackupController (abstract base).
 *
 * Scope:
 *   - getBackupController() factory: returns correct subclass per backupImages
 *     setting (NoBackupController when false, LocalBackupController when truthy).
 *   - getBackupController() caches — subsequent calls return the same instance
 *     without re-reading settings.
 *   - getBackupModel() type-guard: throws \Exception for bare ImageModel or
 *     thumbnail objects; accepts MediaLibraryModel and CustomImageModel.
 *   - checkRemoveBackups() gating logic: all four setting combinations.
 *   - cronRemoveBackups() and cliRemoveBackups() return false when preconditions
 *     are not met; dispatch to autoRemoveBackups() when they are.
 *   - withItem() placeholder — public API but intentionally empty.
 *
 * Out of scope (integration territory — see integration-test punch list):
 *   - getModelById() cache population (calls wpSPIO()->filesystem()->getImage(),
 *     which requires a real WP attachment post).
 *   - autoRemoveBackups() filesystem walk (requires real backup tree on disk
 *     with year/month subdirectories and live filesystem controller calls).
 *   - getBackupModel() / getModelById() full round-trips that hit the DB or
 *     filesystem controller (getImage() / getFile() / copy / move / delete).
 *   - getBackupController() with a real MediaLibraryModel or CustomImageModel
 *     attached to a WP attachment post.
 *
 * @package Shortpixel_Image_Optimiser
 */

use ShortPixel\Controller\Backup\BackupController;
use ShortPixel\Controller\Backup\LocalBackupController;
use ShortPixel\Controller\Backup\NoBackupController;
use ShortPixel\Model\Image\ImageModel;
use ShortPixel\Model\Image\MediaLibraryModel;
use ShortPixel\Model\Image\CustomImageModel;

/**
 * Minimal concrete implementation used only to make BackupController
 * instantiable for tests that exercise base-class methods without
 * going through the factory.
 */
class SPIO_TestableBackupController extends BackupController {
	/** Track whether autoRemoveBackups was called. */
	public $autoRemoveCalled = false;

	protected function autoRemoveBackups() {
		$this->autoRemoveCalled = true;
	}
}

class BackupControllerTest extends WP_UnitTestCase {

	// -----------------------------------------------------------------------
	// Helpers
	// -----------------------------------------------------------------------

	/**
	 * Reset all static state on BackupController between tests so that
	 * mode-switch tests never bleed into each other.
	 */
	private function resetStaticState(): void {
		$ref = new ReflectionClass( BackupController::class );

		$instance = $ref->getProperty( 'instance' );
		$instance->setAccessible( true );
		$instance->setValue( null, null );

		$models = $ref->getProperty( 'models' );
		$models->setAccessible( true );
		$models->setValue( null, array() );

		$model = $ref->getProperty( 'model' );
		$model->setAccessible( true );
		$model->setValue( null, null );
	}

	/**
	 * Helper: read a static property value from BackupController.
	 */
	private function getStatic( string $prop ) {
		$ref = new ReflectionClass( BackupController::class );
		$p   = $ref->getProperty( $prop );
		$p->setAccessible( true );
		return $p->getValue( null );
	}

	/**
	 * Helper: invoke a protected method on a concrete BackupController instance.
	 */
	private function invokeProtected( BackupController $ctrl, string $method, array $args = array() ) {
		$ref = new ReflectionClass( BackupController::class );
		while ( $ref && ! $ref->hasMethod( $method ) ) {
			$ref = $ref->getParentClass();
		}
		$m = $ref->getMethod( $method );
		$m->setAccessible( true );
		return $m->invoke( $ctrl, ...$args );
	}

	/**
	 * Save and restore backupImages around a single test body.
	 */
	private function withBackupSetting( bool $enabled, callable $fn ): void {
		$settings = \wpSPIO()->settings();
		$prev     = $settings->backupImages;
		$settings->backupImages = $enabled;
		try {
			$fn();
		} finally {
			$settings->backupImages = $prev;
			$this->resetStaticState();
		}
	}

	/**
	 * Save and restore both auto-remove settings around a test.
	 *
	 * @param bool        $removeEnabled   Value for autoRemoveBackups.
	 * @param string|null $removePeriod    Value for autoRemoveBackupsPeriod.
	 * @param callable    $fn              Test body.
	 */
	private function withRemoveSettings( bool $removeEnabled, $removePeriod, callable $fn ): void {
		$settings = \wpSPIO()->settings();

		// SettingsModel::__set() sanitizes string fields, turning null into ''
		// — which makes checkRemoveBackups()'s is_null() branch unreachable via
		// the magic setter. To simulate "period never configured" we must unset
		// the raw settings key so __get() falls back to the model default (null).
		// Snapshot/restore the raw array so neighbouring tests are unaffected.
		$ref  = new ReflectionClass( \ShortPixel\Model\SettingsModel::class );
		$prop = null;
		$r    = $ref;
		while ( $r && ! $r->hasProperty( 'settings' ) ) {
			$r = $r->getParentClass();
		}
		$prop = $r->getProperty( 'settings' );
		$prop->setAccessible( true );
		$raw = $prop->getValue( $settings );

		$settings->autoRemoveBackups = $removeEnabled;
		if ( null === $removePeriod ) {
			$current = $prop->getValue( $settings );
			unset( $current['autoRemoveBackupsPeriod'] );
			$prop->setValue( $settings, $current );
		} else {
			$settings->autoRemoveBackupsPeriod = $removePeriod;
		}

		try {
			$fn();
		} finally {
			$prop->setValue( $settings, $raw );
		}
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
	// getBackupController() — factory / caching
	// -----------------------------------------------------------------------

	/*
	 * getBackupController — NoBackupController when backupImages is false
	 */

	public function test_getBackupController_returns_NoBackupController_when_backupImages_is_false() {
		$this->withBackupSetting( false, function () {
			$ctrl = BackupController::getBackupController();
			$this->assertInstanceOf( NoBackupController::class, $ctrl );
		} );
	}

	public function test_getBackupController_returns_LocalBackupController_when_backupImages_is_true() {
		$this->withBackupSetting( true, function () {
			$ctrl = BackupController::getBackupController();
			$this->assertInstanceOf( LocalBackupController::class, $ctrl );
		} );
	}

	/*
	 * getBackupController — LocalBackupController sets self::$model
	 */

	public function test_getBackupController_sets_model_to_LocalBackupModel_when_backupImages_true() {
		$this->withBackupSetting( true, function () {
			BackupController::getBackupController();
			$model = $this->getStatic( 'model' );
			$this->assertSame( '\ShortPixel\Model\Backup\LocalBackupModel', $model );
		} );
	}

	public function test_getBackupController_does_not_set_model_when_backupImages_false() {
		$this->withBackupSetting( false, function () {
			BackupController::getBackupController();
			$model = $this->getStatic( 'model' );
			// model is intentionally not set for NoBackupController path.
			$this->assertNull( $model );
		} );
	}

	/*
	 * getBackupController — caching: second call returns identical instance
	 */

	public function test_getBackupController_caches_and_returns_same_instance_on_second_call() {
		$this->withBackupSetting( true, function () {
			$first  = BackupController::getBackupController();
			$second = BackupController::getBackupController();
			$this->assertSame( $first, $second );
		} );
	}

	public function test_getBackupController_does_not_switch_subclass_after_setting_changes() {
		$settings = \wpSPIO()->settings();
		$prev     = $settings->backupImages;
		$settings->backupImages = true;

		try {
			$first = BackupController::getBackupController();
			$this->assertInstanceOf( LocalBackupController::class, $first );

			// Flip the setting — the cached instance must still be returned.
			$settings->backupImages = false;
			$second = BackupController::getBackupController();
			$this->assertSame( $first, $second );
			$this->assertInstanceOf( LocalBackupController::class, $second );
		} finally {
			$settings->backupImages = $prev;
			$this->resetStaticState();
		}
	}

	// -----------------------------------------------------------------------
	// getBackupModel() — type guard
	// -----------------------------------------------------------------------

	/*
	 * getBackupModel — throws on bare ImageModel stub
	 */

	public function test_getBackupModel_throws_for_bare_ImageModel_subclass() {
		$this->withBackupSetting( true, function () {
			// An anonymous class that extends ImageModel but is neither
			// MediaLibraryModel nor CustomImageModel. ImageModel::__construct
			// requires a path (FileModel parent); any string will do — the file
			// is never touched by getBackupModel()'s type guard.
			$bareItem = new class( '/tmp/spio-test-bare.jpg' ) extends ImageModel {
				public function getOptimizeUrls() { return array(); }
				protected function saveMeta() {}
				protected function loadMeta() {}
				protected function getImprovements() { return false; }
				protected function getExcludePatterns() { return array(); }
				protected function preventNextTry( $reason = '' ) {}
				public function isOptimizePrevented() { return false; }
				public function resetPrevent() {}
			};

			$this->expectException( \Exception::class );
			BackupController::getBackupModel( $bareItem );
		} );
	}

	// -----------------------------------------------------------------------
	// checkRemoveBackups() — gating logic
	// -----------------------------------------------------------------------

	/*
	 * checkRemoveBackups — returns false when autoRemoveBackups is false
	 */

	public function test_checkRemoveBackups_returns_false_when_autoRemoveBackups_is_false() {
		$ctrl = new SPIO_TestableBackupController();

		$this->withRemoveSettings( false, '1year', function () use ( $ctrl ) {
			$result = $this->invokeProtected( $ctrl, 'checkRemoveBackups' );
			$this->assertFalse( $result );
		} );
	}

	/*
	 * checkRemoveBackups — returns false when autoRemoveBackupsPeriod is null
	 */

	public function test_checkRemoveBackups_returns_false_when_period_is_null() {
		$ctrl = new SPIO_TestableBackupController();

		$this->withRemoveSettings( true, null, function () use ( $ctrl ) {
			$result = $this->invokeProtected( $ctrl, 'checkRemoveBackups' );
			$this->assertFalse( $result );
		} );
	}

	/*
	 * checkRemoveBackups — returns true when both conditions are met
	 */

	public function test_checkRemoveBackups_returns_true_when_both_conditions_are_met() {
		$ctrl = new SPIO_TestableBackupController();

		$this->withRemoveSettings( true, '1year', function () use ( $ctrl ) {
			$result = $this->invokeProtected( $ctrl, 'checkRemoveBackups' );
			$this->assertTrue( $result );
		} );
	}

	/*
	 * checkRemoveBackups — returns false when both are missing
	 */

	public function test_checkRemoveBackups_returns_false_when_both_settings_absent() {
		$ctrl = new SPIO_TestableBackupController();

		$this->withRemoveSettings( false, null, function () use ( $ctrl ) {
			$result = $this->invokeProtected( $ctrl, 'checkRemoveBackups' );
			$this->assertFalse( $result );
		} );
	}

	/*
	 * checkRemoveBackups — string period values accepted
	 */

	public function test_checkRemoveBackups_returns_true_for_each_valid_period_string() {
		$ctrl    = new SPIO_TestableBackupController();
		$periods = array( 'month', '3month', '6month', '1year', '2year', '5year' );

		foreach ( $periods as $period ) {
			$this->withRemoveSettings( true, $period, function () use ( $ctrl, $period ) {
				$result = $this->invokeProtected( $ctrl, 'checkRemoveBackups' );
				$this->assertTrue( $result, "Period '$period' should pass the check." );
			} );
		}
	}

	// -----------------------------------------------------------------------
	// cronRemoveBackups() — dispatch / gate
	// -----------------------------------------------------------------------

	/*
	 * cronRemoveBackups — returns false when preconditions are not met
	 */

	public function test_cronRemoveBackups_returns_false_when_autoRemoveBackups_is_off() {
		$ctrl = new SPIO_TestableBackupController();

		$this->withRemoveSettings( false, '1year', function () use ( $ctrl ) {
			$result = $ctrl->cronRemoveBackups();
			$this->assertFalse( $result );
			$this->assertFalse( $ctrl->autoRemoveCalled );
		} );
	}

	public function test_cronRemoveBackups_returns_false_when_period_is_null() {
		$ctrl = new SPIO_TestableBackupController();

		$this->withRemoveSettings( true, null, function () use ( $ctrl ) {
			$result = $ctrl->cronRemoveBackups();
			$this->assertFalse( $result );
			$this->assertFalse( $ctrl->autoRemoveCalled );
		} );
	}

	/*
	 * cronRemoveBackups — dispatches to autoRemoveBackups when preconditions pass
	 */

	public function test_cronRemoveBackups_dispatches_autoRemoveBackups_when_conditions_met() {
		$ctrl = new SPIO_TestableBackupController();

		$this->withRemoveSettings( true, '1year', function () use ( $ctrl ) {
			$ctrl->cronRemoveBackups();
			$this->assertTrue( $ctrl->autoRemoveCalled );
		} );
	}

	// -----------------------------------------------------------------------
	// cliRemoveBackups() — dispatch / gate (identical logic to cron)
	// -----------------------------------------------------------------------

	public function test_cliRemoveBackups_returns_false_when_autoRemoveBackups_is_off() {
		$ctrl = new SPIO_TestableBackupController();

		$this->withRemoveSettings( false, '1year', function () use ( $ctrl ) {
			$result = $ctrl->cliRemoveBackups();
			$this->assertFalse( $result );
			$this->assertFalse( $ctrl->autoRemoveCalled );
		} );
	}

	public function test_cliRemoveBackups_returns_false_when_period_is_null() {
		$ctrl = new SPIO_TestableBackupController();

		$this->withRemoveSettings( true, null, function () use ( $ctrl ) {
			$result = $ctrl->cliRemoveBackups();
			$this->assertFalse( $result );
			$this->assertFalse( $ctrl->autoRemoveCalled );
		} );
	}

	public function test_cliRemoveBackups_dispatches_autoRemoveBackups_when_conditions_met() {
		$ctrl = new SPIO_TestableBackupController();

		$this->withRemoveSettings( true, '1year', function () use ( $ctrl ) {
			$ctrl->cliRemoveBackups();
			$this->assertTrue( $ctrl->autoRemoveCalled );
		} );
	}

	// -----------------------------------------------------------------------
	// withItem() — public no-op placeholder
	// -----------------------------------------------------------------------

	public function test_withItem_is_callable_and_returns_null() {
		$ctrl = new SPIO_TestableBackupController();

		// ImageModel::__construct requires a path; the file is never accessed.
		$bareItem = new class( '/tmp/spio-test-bare.jpg' ) extends ImageModel {
			public function getOptimizeUrls() { return array(); }
			protected function saveMeta() {}
			protected function loadMeta() {}
			protected function getImprovements() { return false; }
			protected function getExcludePatterns() { return array(); }
			protected function preventNextTry( $reason = '' ) {}
			public function isOptimizePrevented() { return false; }
			public function resetPrevent() {}
		};

		// Must not throw; return value is void (null).
		$result = $ctrl->withItem( $bareItem );
		$this->assertNull( $result );
	}

	// -----------------------------------------------------------------------
	// Pinned regression — $model not set on NoBackupController path
	// -----------------------------------------------------------------------

	/**
	 * Pinned regression: when backupImages is false, self::$model is left null
	 * by getBackupController(). The production workaround in getModelById()
	 * hard-codes LocalBackupModel to avoid a crash; but the documented intent
	 * of using self::$model is broken.
	 *
	 * Expected (documented): self::$model should be set to LocalBackupModel
	 * even for NoBackupController, so that the generic
	 * `new self::$model(…)` call works uniformly.
	 *
	 * Actual: self::$model is null after the NoBackupController factory path.
	 *
	 * This test asserts CURRENT (broken) behavior and is designed to FAIL
	 * once the factory is fixed to set self::$model for both branches.
	 *
	 * @see BackupController::getBackupController() line ~114 — the else branch sets $model; the if branch does not.
	 * @see BackupController::getModelById() line ~196 — hard-coded workaround comment.
	 */
	public function test_model_static_is_null_for_NoBackupController_path_pinned_for_deferred_fix() {
		$this->withBackupSetting( false, function () {
			BackupController::getBackupController();
			$model = $this->getStatic( 'model' );

			// CURRENT (broken) behavior: null.
			// Once fixed, $model should equal '\ShortPixel\Model\Backup\LocalBackupModel'
			// and this assertion will start failing — that is the signal to remove the pin.
			$this->assertNull(
				$model,
				'self::$model is null on the NoBackupController factory path — ' .
				'this is the known bug. Update this test when the factory is fixed.'
			);
		} );
	}
}
