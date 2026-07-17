<?php
/**
 * Tests for ShortPixel\Controller\Backup\NoBackupController.
 *
 * Scope:
 *   - Class hierarchy: NoBackupController extends LocalBackupController
 *     extends BackupController.
 *   - Factory selection: getBackupController() returns NoBackupController
 *     exactly when backupImages is false (boolean).
 *   - No methods are overridden: all public and protected behaviour is
 *     inherited; verify the class adds nothing new (reflection).
 *   - $backupDirectory private property exists but is intentionally unused
 *     (docblock states it is a dead remnant of an earlier design).
 *   - Inherited checkRemoveBackups() short-circuits when backupImages is off
 *     (see class docblock: "auto-removal cron / WP-CLI paths … will
 *     short-circuit in checkRemoveBackups()").
 *
 *   NOTE: The docblock statement that checkRemoveBackups() short-circuits
 *   "because `backupImages` is off" is INACCURATE — checkRemoveBackups()
 *   actually reads autoRemoveBackups and autoRemoveBackupsPeriod, not
 *   backupImages. The true short-circuit is in the factory not setting
 *   self::$model, and the optimizer skipping createBackupFile() externally.
 *   This test pins the ACTUAL behavior of checkRemoveBackups() on the
 *   NoBackupController instance.
 *
 * Out of scope:
 *   - autoRemoveBackups() / filesystem walk — inherited from
 *     LocalBackupController, covered there and in integration tests.
 *   - getModelById() full round-trip — requires WP attachment + filesystem.
 *   - Any method that calls wpSPIO()->filesystem().
 *
 * @package Shortpixel_Image_Optimiser
 */

use ShortPixel\Controller\Backup\BackupController;
use ShortPixel\Controller\Backup\LocalBackupController;
use ShortPixel\Controller\Backup\NoBackupController;

class NoBackupControllerTest extends WP_UnitTestCase {

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
	private function withBackupDisabled( callable $fn ): void {
		$settings = \wpSPIO()->settings();
		$prev     = $settings->backupImages;
		$settings->backupImages = false;
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
	 * Invoke a protected/private method on a NoBackupController instance.
	 * Walks the parent chain to find the method's declaring class.
	 */
	private function invokeProtected( NoBackupController $ctrl, string $method, array $args = array() ) {
		$ref = new ReflectionClass( $ctrl );
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
	// Class hierarchy
	// -----------------------------------------------------------------------

	/*
	 * NoBackupController extends LocalBackupController extends BackupController
	 */

	public function test_extends_LocalBackupController() {
		$this->assertTrue( is_subclass_of( NoBackupController::class, LocalBackupController::class ) );
	}

	public function test_extends_BackupController() {
		$this->assertTrue( is_subclass_of( NoBackupController::class, BackupController::class ) );
	}

	public function test_is_instantiable_via_public_constructor() {
		$ctrl = new NoBackupController();
		$this->assertInstanceOf( NoBackupController::class, $ctrl );
	}

	// -----------------------------------------------------------------------
	// No own methods — the class adds nothing new
	// -----------------------------------------------------------------------

	/*
	 * NoBackupController declares no methods of its own
	 */

	public function test_class_declares_no_own_methods() {
		$ref     = new ReflectionClass( NoBackupController::class );
		$ownMethods = $ref->getMethods();

		// Filter to methods actually declared in NoBackupController (not inherited).
		$declared = array_filter(
			$ownMethods,
			fn( ReflectionMethod $m ) => $m->getDeclaringClass()->getName() === NoBackupController::class
		);

		$this->assertCount(
			0,
			$declared,
			'NoBackupController should declare no methods of its own.'
		);
	}

	/*
	 * $backupDirectory private property exists but is unused (dead remnant)
	 */

	public function test_backupDirectory_private_property_exists() {
		$ref = new ReflectionClass( NoBackupController::class );
		$this->assertTrue(
			$ref->hasProperty( 'backupDirectory' ),
			'The dead $backupDirectory property should still exist per the class docblock.'
		);
	}

	public function test_backupDirectory_property_is_private() {
		$ref  = new ReflectionClass( NoBackupController::class );
		$prop = $ref->getProperty( 'backupDirectory' );
		$this->assertTrue( $prop->isPrivate() );
	}

	public function test_backupDirectory_is_null_after_construction() {
		$ctrl = new NoBackupController();
		$ref  = new ReflectionClass( NoBackupController::class );
		$prop = $ref->getProperty( 'backupDirectory' );
		$prop->setAccessible( true );
		$this->assertNull( $prop->getValue( $ctrl ) );
	}

	// -----------------------------------------------------------------------
	// Factory selection
	// -----------------------------------------------------------------------

	public function test_getBackupController_returns_NoBackupController_when_backupImages_false() {
		$this->withBackupDisabled( function () {
			$ctrl = BackupController::getBackupController();
			$this->assertInstanceOf( NoBackupController::class, $ctrl );
		} );
	}

	public function test_getBackupController_NoBackupController_is_same_instance_on_repeat_calls() {
		$this->withBackupDisabled( function () {
			$first  = BackupController::getBackupController();
			$second = BackupController::getBackupController();
			$this->assertSame( $first, $second );
		} );
	}

	// -----------------------------------------------------------------------
	// Inherited checkRemoveBackups() — behavior on NoBackupController instance
	// -----------------------------------------------------------------------

	/*
	 * Behavioral note: checkRemoveBackups() in BackupController reads
	 * autoRemoveBackups and autoRemoveBackupsPeriod — NOT backupImages.
	 * Even when backupImages=false (i.e. NoBackupController is active),
	 * checkRemoveBackups() returns true if both auto-remove settings are set.
	 * The class docblock's claim that "auto-removal … will short-circuit in
	 * checkRemoveBackups() because `backupImages` is off" is therefore
	 * misleading — the real gate is autoRemoveBackups + autoRemoveBackupsPeriod.
	 * These tests pin the ACTUAL behavior.
	 */

	public function test_inherited_checkRemoveBackups_returns_false_when_autoRemoveBackups_off() {
		$ctrl = new NoBackupController();

		$this->withRemoveSettings( false, '1year', function () use ( $ctrl ) {
			$result = $this->invokeProtected( $ctrl, 'checkRemoveBackups' );
			$this->assertFalse( $result );
		} );
	}

	public function test_inherited_checkRemoveBackups_returns_false_when_period_null() {
		$ctrl = new NoBackupController();

		$this->withRemoveSettings( true, null, function () use ( $ctrl ) {
			$result = $this->invokeProtected( $ctrl, 'checkRemoveBackups' );
			$this->assertFalse( $result );
		} );
	}

	public function test_inherited_checkRemoveBackups_returns_true_when_both_set() {
		$ctrl = new NoBackupController();

		$this->withRemoveSettings( true, '1year', function () use ( $ctrl ) {
			$result = $this->invokeProtected( $ctrl, 'checkRemoveBackups' );
			// Actual behavior: returns true regardless of backupImages.
			$this->assertTrue( $result );
		} );
	}

	// -----------------------------------------------------------------------
	// cronRemoveBackups / cliRemoveBackups — inherited gate
	// -----------------------------------------------------------------------

	public function test_cronRemoveBackups_returns_false_when_conditions_not_met() {
		$ctrl = new NoBackupController();

		$this->withRemoveSettings( false, null, function () use ( $ctrl ) {
			$result = $ctrl->cronRemoveBackups();
			$this->assertFalse( $result );
		} );
	}

	public function test_cliRemoveBackups_returns_false_when_conditions_not_met() {
		$ctrl = new NoBackupController();

		$this->withRemoveSettings( false, null, function () use ( $ctrl ) {
			$result = $ctrl->cliRemoveBackups();
			$this->assertFalse( $result );
		} );
	}

	// -----------------------------------------------------------------------
	// Pinned behavioral regression — docblock vs. actual checkRemoveBackups
	// -----------------------------------------------------------------------

	/**
	 * Pinned documentation bug: the NoBackupController class docblock states
	 * "The auto-removal cron / WP-CLI paths … will short-circuit in
	 * checkRemoveBackups() because `backupImages` is off."
	 *
	 * Actual behavior: checkRemoveBackups() does NOT read backupImages at all.
	 * It reads autoRemoveBackups + autoRemoveBackupsPeriod. When both of
	 * those are set, checkRemoveBackups() returns true even when
	 * NoBackupController is active (i.e. backupImages=false).
	 *
	 * Expected (per docblock): checkRemoveBackups() should return false when
	 * backupImages is false.
	 *
	 * Actual: returns true (because the real gate is the two auto-remove
	 * settings, not backupImages).
	 *
	 * This test asserts CURRENT actual behavior. It will need to be
	 * re-evaluated if checkRemoveBackups() is ever updated to also gate on
	 * backupImages — at which point the class docblock would become correct.
	 */
	public function test_checkRemoveBackups_returns_true_despite_backupImages_off_pinned_for_deferred_fix() {
		// Ensure NoBackupController is active.
		$this->withBackupDisabled( function () {
			$ctrl = new NoBackupController();

			$this->withRemoveSettings( true, '1year', function () use ( $ctrl ) {
				$result = $this->invokeProtected( $ctrl, 'checkRemoveBackups' );

				// CURRENT (actual) behavior: true, even though backupImages=false.
				// The docblock says it should short-circuit here. It does not.
				// If this test starts failing, it means checkRemoveBackups() was
				// updated to gate on backupImages — update the docblock accordingly.
				$this->assertTrue(
					$result,
					'checkRemoveBackups() does NOT read backupImages; it returns true ' .
					'when autoRemoveBackups+period are set, even on NoBackupController. ' .
					'This pin will fail if the method is fixed to match its docblock.'
				);
			} );
		} );
	}
}
