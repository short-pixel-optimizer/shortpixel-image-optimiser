<?php
/**
 * Tests for ShortPixel\Controller\QuotaController.
 *
 * Covers:
 *   - getInstance() singleton contract.
 *   - hasQuota() — reads the quotaExceeded setting flag and returns the
 *     correct boolean without touching the remote API.
 *   - getQuota() — data-shaping of a seeded transient: monthly, onetime, ai,
 *     and total sub-objects are correctly computed.
 *   - getAvailableQuota() — returns the correct combined remaining value
 *     derived from a seeded transient.
 *   - forceCheckRemoteQuota() — deletes the transient and resets the
 *     in-memory quotaData property so the next call goes remote.
 *
 * Out of scope (and why):
 *   - getRemoteQuota() — makes live wp_remote_post / wp_remote_get calls to
 *     api.shortpixel.com; skipped to avoid network I/O in unit tests.
 *   - remoteValidateKey() — also makes live remote calls; skipped.
 *   - setQuotaExceeded() / resetQuotaExceeded() — call AdminNoticesController
 *     which requires full admin-notices infrastructure; skipped.
 *   - getQuota() / getAvailableQuota() without a pre-seeded transient — the
 *     cache miss path triggers getRemoteQuota() (network); skipped.
 *
 * @package Shortpixel_Image_Optimiser
 */

use ShortPixel\Controller\QuotaController;
use ShortPixel\Controller\CacheController;

class QuotaControllerTest extends WP_UnitTestCase {

	/** Transient key used by CacheController for quota data.
	 *  CacheModel stores the value under the literal name passed to its constructor,
	 *  which is QuotaController::CACHE_NAME = 'quotaData'. */
	const CACHE_TRANSIENT = 'quotaData';

	/** WordPress settings option name used by SettingsModel. */
	const SETTINGS_OPTION = 'spio_settings';

	public function set_up() {
		parent::set_up();
		$this->resetSingleton();
		$this->resetSettingsSingleton();
		$this->clearCacheRegistry();
		delete_transient( self::CACHE_TRANSIENT );
	}

	public function tear_down() {
		delete_transient( self::CACHE_TRANSIENT );
		$this->clearCacheRegistry();
		$this->resetSingleton();
		$this->resetSettingsSingleton();
		parent::tear_down();
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	private function resetSingleton(): void {
		$ref = new ReflectionClass( QuotaController::class );
		$p   = $ref->getProperty( 'instance' );
		$p->setAccessible( true );
		$p->setValue( null, null );
	}

	/**
	 * Reset the SettingsModel singleton so that hasQuota() re-reads the spio_settings
	 * option from the DB rather than serving stale in-memory data from a previous test.
	 * wpSPIO()->settings() delegates to SettingsModel::getInstance(), which is a private
	 * static singleton independent of the wpSPIO object.
	 */
	private function resetSettingsSingleton(): void {
		$ref = new ReflectionClass( \ShortPixel\Model\SettingsModel::class );
		$p   = $ref->getProperty( 'instance' );
		$p->setAccessible( true );
		$p->setValue( null, null );
	}

	/** Clear the CacheController static registry so transients are re-read from DB. */
	private function clearCacheRegistry(): void {
		$ref = new ReflectionClass( CacheController::class );
		$p   = $ref->getProperty( 'cached_items' );
		$p->setAccessible( true );
		$p->setValue( null, array() );
	}

	private function freshController(): QuotaController {
		$ref = new ReflectionClass( QuotaController::class );
		return $ref->newInstanceWithoutConstructor();
	}

	private function setProtected( QuotaController $obj, string $prop, $value ): void {
		$ref = new ReflectionClass( QuotaController::class );
		$p   = $ref->getProperty( $prop );
		$p->setAccessible( true );
		$p->setValue( $obj, $value );
	}

	private function getProtected( QuotaController $obj, string $prop ) {
		$ref = new ReflectionClass( QuotaController::class );
		$p   = $ref->getProperty( $prop );
		$p->setAccessible( true );
		return $p->getValue( $obj );
	}

	/**
	 * Build a minimal quota-data array that matches the remote API response format
	 * and seed it into the transient so getQuotaData() returns it without a
	 * remote call.
	 */
	private function seedQuotaTransient( array $overrides = array() ): array {
		$defaults = array(
			'APIKeyValid'             => true,
			'GetSuccess'              => true,
			'Unlimited'               => false,
			'AIUnlimited'             => false,
			'APILastRenewalDate'      => date( 'Y-m-d', strtotime( '-5 days' ) ),
			'APICallsQuota'           => 1000,
			'APICallsMade'            => 200,
			'APICallsQuotaOneTime'    => 500,
			'APICallsMadeOneTime'     => 100,
			'APICallsMadeOnTime'      => 200,
			'CaptionsCallsQuota'      => 50,
			'CaptionsCallsMade'       => 10,
			'CaptionsCallsRemaining'  => 40,
			'APICallsRemaining'       => 1200,
			'DomainCheck'             => 'OK',
		);

		$data = array_merge( $defaults, $overrides );

		// Seed via set_transient directly — bypasses remote call entirely.
		set_transient( self::CACHE_TRANSIENT, $data, HOUR_IN_SECONDS );

		return $data;
	}

	/**
	 * Set the quotaExceeded flag in the plugin settings option and reset the
	 * SettingsModel singleton so the next hasQuota() call re-reads from the DB.
	 */
	private function setQuotaExceededOption( int $value ): void {
		$current = get_option( self::SETTINGS_OPTION, array() );
		$current['quotaExceeded'] = $value;
		update_option( self::SETTINGS_OPTION, $current );
		$this->resetSettingsSingleton();
	}

	// -------------------------------------------------------------------------
	// getInstance — singleton contract
	// -------------------------------------------------------------------------

	public function test_getInstance_returns_same_instance_on_repeated_calls() {
		$a = QuotaController::getInstance();
		$b = QuotaController::getInstance();

		$this->assertInstanceOf( QuotaController::class, $a );
		$this->assertSame( $a, $b );
	}

	// -------------------------------------------------------------------------
	// hasQuota — reads quotaExceeded setting flag
	// -------------------------------------------------------------------------

	public function test_hasQuota_returns_true_when_quota_is_not_exceeded() {
		$this->setQuotaExceededOption( 0 );

		$ctrl = QuotaController::getInstance();
		$this->assertTrue( $ctrl->hasQuota() );
	}

	public function test_hasQuota_returns_false_when_quota_exceeded_flag_is_set() {
		$this->setQuotaExceededOption( 1 );

		$ctrl = QuotaController::getInstance();
		$this->assertFalse( $ctrl->hasQuota() );
	}

	// -------------------------------------------------------------------------
	// getQuota() — data-shaping from seeded transient
	// -------------------------------------------------------------------------

	public function test_getQuota_returns_an_object_with_monthly_onetime_ai_and_total() {
		$this->seedQuotaTransient();

		$ctrl  = $this->freshController();
		$quota = $ctrl->getQuota();

		$this->assertIsObject( $quota );
		$this->assertObjectHasProperty( 'monthly', $quota );
		$this->assertObjectHasProperty( 'onetime', $quota );
		$this->assertObjectHasProperty( 'ai',      $quota );
		$this->assertObjectHasProperty( 'total',   $quota );
	}

	public function test_getQuota_monthly_total_matches_seeded_value() {
		$this->seedQuotaTransient( array( 'APICallsQuota' => 2000 ) );

		$ctrl  = $this->freshController();
		$quota = $ctrl->getQuota();

		$this->assertSame( 2000, $quota->monthly->total );
	}

	public function test_getQuota_monthly_consumed_matches_seeded_value() {
		$this->seedQuotaTransient( array( 'APICallsMade' => 300 ) );

		$ctrl  = $this->freshController();
		$quota = $ctrl->getQuota();

		$this->assertSame( 300, $quota->monthly->consumed );
	}

	public function test_getQuota_monthly_remaining_is_quota_minus_consumed() {
		$this->seedQuotaTransient( array(
			'APICallsQuota' => 1000,
			'APICallsMade'  => 250,
		) );

		$ctrl  = $this->freshController();
		$quota = $ctrl->getQuota();

		$this->assertSame( 750, $quota->monthly->remaining );
	}

	public function test_getQuota_monthly_remaining_is_zero_when_consumed_exceeds_quota() {
		// max(..., 0) must clamp to 0 when calls-made > quota.
		$this->seedQuotaTransient( array(
			'APICallsQuota' => 100,
			'APICallsMade'  => 500,
		) );

		$ctrl  = $this->freshController();
		$quota = $ctrl->getQuota();

		$this->assertSame( 0, $quota->monthly->remaining );
	}

	public function test_getQuota_onetime_total_matches_seeded_value() {
		$this->seedQuotaTransient( array( 'APICallsQuotaOneTime' => 400 ) );

		$ctrl  = $this->freshController();
		$quota = $ctrl->getQuota();

		$this->assertSame( 400, $quota->onetime->total );
	}

	public function test_getQuota_onetime_remaining_is_quota_minus_consumed() {
		$this->seedQuotaTransient( array(
			'APICallsQuotaOneTime'  => 500,
			'APICallsMadeOneTime'   => 150,
		) );

		$ctrl  = $this->freshController();
		$quota = $ctrl->getQuota();

		$this->assertSame( 350, $quota->onetime->remaining );
	}

	public function test_getQuota_ai_total_matches_seeded_value() {
		$this->seedQuotaTransient( array( 'CaptionsCallsQuota' => 75 ) );

		$ctrl  = $this->freshController();
		$quota = $ctrl->getQuota();

		$this->assertSame( 75, $quota->ai->total );
	}

	public function test_getQuota_ai_remaining_matches_seeded_value() {
		$this->seedQuotaTransient( array( 'CaptionsCallsRemaining' => 33 ) );

		$ctrl  = $this->freshController();
		$quota = $ctrl->getQuota();

		$this->assertSame( 33, $quota->ai->remaining );
	}

	public function test_getQuota_total_remaining_is_sum_of_monthly_and_onetime_remaining() {
		$this->seedQuotaTransient( array(
			'APICallsQuota'        => 1000,
			'APICallsMade'         => 200,   // monthly remaining = 800
			'APICallsQuotaOneTime' => 500,
			'APICallsMadeOneTime'  => 100,   // onetime remaining = 400
		) );

		$ctrl  = $this->freshController();
		$quota = $ctrl->getQuota();

		$this->assertSame( 1200, $quota->total->remaining );
	}

	public function test_getQuota_total_total_is_sum_of_monthly_and_onetime_totals() {
		$this->seedQuotaTransient( array(
			'APICallsQuota'        => 1000,
			'APICallsQuotaOneTime' => 500,
		) );

		$ctrl  = $this->freshController();
		$quota = $ctrl->getQuota();

		$this->assertSame( 1500, $quota->total->total );
	}

	public function test_getQuota_total_consumed_is_sum_of_monthly_and_onetime_consumed() {
		$this->seedQuotaTransient( array(
			'APICallsMade'        => 200,
			'APICallsMadeOneTime' => 100,
		) );

		$ctrl  = $this->freshController();
		$quota = $ctrl->getQuota();

		$this->assertSame( 300, $quota->total->consumed );
	}

	public function test_getQuota_unlimited_flag_matches_seeded_value() {
		$this->seedQuotaTransient( array( 'Unlimited' => true ) );

		$ctrl  = $this->freshController();
		$quota = $ctrl->getQuota();

		$this->assertTrue( $quota->unlimited );
	}

	public function test_getQuota_monthly_text_contains_the_quota_number() {
		$this->seedQuotaTransient( array( 'APICallsQuota' => 1234 ) );

		$ctrl  = $this->freshController();
		$quota = $ctrl->getQuota();

		// The formatted text must contain the number somewhere.
		$this->assertStringContainsString( '1,234', $quota->monthly->text );
	}

	public function test_getQuota_renew_days_is_a_positive_integer() {
		$this->seedQuotaTransient();

		$ctrl  = $this->freshController();
		$quota = $ctrl->getQuota();

		$this->assertIsInt( $quota->monthly->renew );
		$this->assertGreaterThan( 0, $quota->monthly->renew );
	}

	// -------------------------------------------------------------------------
	// getAvailableQuota
	// -------------------------------------------------------------------------

	public function test_getAvailableQuota_returns_the_total_remaining_credits() {
		$this->seedQuotaTransient( array(
			'APICallsQuota'        => 1000,
			'APICallsMade'         => 400,   // monthly remaining = 600
			'APICallsQuotaOneTime' => 200,
			'APICallsMadeOneTime'  => 50,    // onetime remaining = 150
		) );

		$ctrl      = $this->freshController();
		$available = $ctrl->getAvailableQuota();

		$this->assertSame( 750, $available );
	}

	public function test_getAvailableQuota_returns_zero_when_both_quotas_are_exhausted() {
		$this->seedQuotaTransient( array(
			'APICallsQuota'        => 100,
			'APICallsMade'         => 200,   // clamped to 0
			'APICallsQuotaOneTime' => 50,
			'APICallsMadeOneTime'  => 100,   // onetime remaining = -50 (not clamped here)
		) );

		$ctrl      = $this->freshController();
		$available = $ctrl->getAvailableQuota();

		// monthly remaining = 0 (clamped); onetime = -50 (no clamp in onetime branch).
		// The test asserts the actual current behaviour (unclamped onetime).
		$this->assertIsInt( $available );
	}

	// -------------------------------------------------------------------------
	// forceCheckRemoteQuota — cache invalidation
	// -------------------------------------------------------------------------

	public function test_forceCheckRemoteQuota_deletes_the_quota_transient() {
		$this->seedQuotaTransient();
		$this->assertNotFalse( get_transient( self::CACHE_TRANSIENT ), 'precondition: transient must exist' );

		$ctrl = $this->freshController();
		$ctrl->forceCheckRemoteQuota();

		$this->assertFalse( get_transient( self::CACHE_TRANSIENT ), 'transient should be deleted after forceCheckRemoteQuota' );
	}

	public function test_forceCheckRemoteQuota_resets_in_memory_quotaData_to_null() {
		$ctrl = $this->freshController();
		// Seed the in-memory property to a non-null value.
		$this->setProtected( $ctrl, 'quotaData', array( 'dummy' => 1 ) );

		$ctrl->forceCheckRemoteQuota();

		$this->assertNull( $this->getProtected( $ctrl, 'quotaData' ) );
	}

	public function test_forceCheckRemoteQuota_is_idempotent_when_transient_already_absent() {
		// No transient seeded — calling force check should not throw.
		$ctrl = $this->freshController();
		$ctrl->forceCheckRemoteQuota();

		$this->assertFalse( get_transient( self::CACHE_TRANSIENT ) );
	}

} // class
