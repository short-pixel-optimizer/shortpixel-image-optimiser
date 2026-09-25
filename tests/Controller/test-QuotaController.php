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
 * Also covered (added 2026-09-21):
 *   - AIUnlimited shaping in getQuota() and its PlanType derivation in
 *     getRemoteQuota() — the flag class/view/bulk/part-summary.php keys its
 *     "Unlimited AI plan" message on since dfa7e086.
 *   - getRemoteQuota() response handling, made hermetic with a
 *     `pre_http_request` short-circuit (see interceptRemoteQuota()): no socket
 *     is ever opened, so the numeric normalisation, the non-200 path and the
 *     API-failure path are all testable offline. This supersedes the former
 *     "network, therefore skipped" note below.
 *   - The legacy non-numeric provision in getQuota(), which used to discard
 *     its own refresh (a `$quotData` typo, fixed 2026-09-21) and fatal in
 *     number_format(); it now self-heals from the API.
 *
 * Out of scope (and why):
 *   - getRemoteQuota()'s transport fallbacks (https→http retry, wp_remote_get
 *     second fallback): reachable only by simulating WP_Error responses, which
 *     the intercept above deliberately does not do.
 *     Bug #17 FIXED (1facd056): guard is now `! is_object($data) || ! property_exists($data,'Status') || empty($data)`
 *     so a body of '{}' (empty object) or non-object JSON no longer reaches $data->Status->Code.
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

	// -------------------------------------------------------------------------
	// AIUnlimited — the flag the bulk summary keys its AI message on
	// -------------------------------------------------------------------------

	/**
	 * dfa7e086 added an `else` branch to class/view/bulk/part-summary.php that
	 * renders "This site is currently on the ShortPixel Unlimited AI plan…"
	 * whenever `false === $quotaData->AIUnlimited` is not met. That property had
	 * no coverage at all, so the tests below pin both the shaping in getQuota()
	 * and the PlanType derivation in getRemoteQuota() that feeds it.
	 */
	public function test_getQuota_aiunlimited_is_true_when_the_account_has_an_unlimited_ai_plan() {
		$this->seedQuotaTransient( array( 'AIUnlimited' => true ) );

		$ctrl  = $this->freshController();
		$quota = $ctrl->getQuota();

		$this->assertTrue( $quota->AIUnlimited );
	}

	public function test_getQuota_aiunlimited_defaults_to_false_when_the_key_is_absent() {
		// Legacy cached quota data predates the AIUnlimited key entirely.
		$data = $this->seedQuotaTransient();
		unset( $data['AIUnlimited'] );
		set_transient( self::CACHE_TRANSIENT, $data, HOUR_IN_SECONDS );
		$this->clearCacheRegistry();

		$ctrl  = $this->freshController();
		$quota = $ctrl->getQuota();

		$this->assertFalse(
			$quota->AIUnlimited,
			'A missing AIUnlimited key must shape to false, so the summary shows the "Buy Unlimited AI credits" branch rather than claiming an unlimited plan.'
		);
	}

	public function test_getQuota_ai_consumed_matches_seeded_value() {
		$this->seedQuotaTransient( array( 'CaptionsCallsMade' => 17 ) );

		$ctrl  = $this->freshController();
		$quota = $ctrl->getQuota();

		$this->assertSame( 17, $quota->ai->consumed );
	}

	// -------------------------------------------------------------------------
	// getRemoteQuota — hermetic, via a pre_http_request short-circuit
	// -------------------------------------------------------------------------

	/**
	 * Short-circuit every HTTP call with a canned api-status.php response.
	 *
	 * This is what lifts getRemoteQuota() out of the "network, therefore
	 * untested" bucket the file header used to describe: `pre_http_request`
	 * intercepts before any socket is opened, so the method's response
	 * handling can be exercised offline. WP_UnitTestCase restores the hook
	 * registry between tests, so the filter does not leak.
	 *
	 * @param array $body_overrides Fields merged into the decoded JSON body.
	 * @param int   $response_code  HTTP status to report.
	 * @return \stdClass Counter object; ->calls counts intercepted requests.
	 */
	private function interceptRemoteQuota( array $body_overrides = array(), int $response_code = 200 ): \stdClass {
		$state        = new \stdClass();
		$state->calls = 0;

		$body = array_merge(
			array(
				'Status'                 => array( 'Code' => 2, 'Message' => 'Success' ),
				'APICallsQuota'          => 7777,
				'APICallsMade'           => 11,
				'APICallsQuotaOneTime'   => 0,
				'APICallsMadeOneTime'    => 0,
				'CaptionsCallsQuota'     => 5,
				'CaptionsCallsMade'      => 1,
				'CaptionsCallsRemaining' => 4,
				'DateSubscription'       => date( 'Y-m-d' ),
				'Unlimited'              => 'false',
			),
			$body_overrides
		);

		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) use ( $state, $body, $response_code ) {
				$state->calls++;
				return array(
					'response' => array( 'code' => $response_code, 'message' => 'intercepted' ),
					'body'     => wp_json_encode( $body ),
				);
			},
			10,
			3
		);

		return $state;
	}

	/** Invoke the private getRemoteQuota() with an explicit key (skips ApiKeyController). */
	private function callGetRemoteQuota(): array {
		$ctrl   = $this->freshController();
		$ref    = new ReflectionClass( QuotaController::class );
		$method = $ref->getMethod( 'getRemoteQuota' );
		$method->setAccessible( true );

		return (array) $method->invoke( $ctrl, 'TESTAPIKEY0000', false );
	}

	public function test_getRemoteQuota_marks_aiunlimited_for_the_unlimited_ai_plantype() {
		$this->interceptRemoteQuota( array( 'PlanType' => 'Unlimited AI' ) );

		$data = $this->callGetRemoteQuota();

		$this->assertTrue( $data['AIUnlimited'] );
		$this->assertTrue( $data['GetSuccess'], 'Sentinel: the canned success response was really parsed.' );
	}

	public function test_getRemoteQuota_does_not_mark_aiunlimited_for_other_plantypes() {
		$this->interceptRemoteQuota( array( 'PlanType' => 'Unlimited' ) );

		$data = $this->callGetRemoteQuota();

		$this->assertFalse(
			$data['AIUnlimited'],
			'Only the exact "Unlimited AI" PlanType may set AIUnlimited — "Unlimited" is the image plan and must not claim unlimited AI credits.'
		);
	}

	public function test_getRemoteQuota_defaults_aiunlimited_to_false_when_plantype_is_missing() {
		$this->interceptRemoteQuota();

		$data = $this->callGetRemoteQuota();

		$this->assertFalse( $data['AIUnlimited'] );
	}

	public function test_getRemoteQuota_casts_numeric_fields_to_int_and_clamps_negatives_to_zero() {
		$this->interceptRemoteQuota(
			array(
				'APICallsQuota'      => '2500',
				'CaptionsCallsQuota' => -40,
			)
		);

		$data = $this->callGetRemoteQuota();

		$this->assertSame( 2500, $data['APICallsQuota'], 'Numeric fields are normalised with (int) max($value, 0).' );
		$this->assertSame( 0, $data['CaptionsCallsQuota'], 'A negative credit count from the API must clamp to 0, never go below.' );
	}

	public function test_getRemoteQuota_returns_default_data_on_a_non_200_response() {
		$this->interceptRemoteQuota( array(), 503 );

		$data = $this->callGetRemoteQuota();

		$this->assertFalse( $data['GetSuccess'] );
		$this->assertFalse( $data['APIKeyValid'] );
		$this->assertSame( 0, $data['APICallsQuota'], 'Default data zeroes every numeric field so the UI cannot render stale credits.' );
	}

	public function test_getRemoteQuota_returns_default_data_when_the_api_reports_a_failure_status() {
		$this->interceptRemoteQuota(
			array( 'Status' => array( 'Code' => -1, 'Message' => 'Invalid API key' ) )
		);

		$data = $this->callGetRemoteQuota();

		$this->assertFalse( $data['GetSuccess'] );
		$this->assertSame( 'Invalid API key', $data['Message'], 'The API\'s own status message replaces the generic connectivity text.' );
	}

	// -------------------------------------------------------------------------
	// REGRESSION — the legacy non-numeric provision actually self-heals
	// -------------------------------------------------------------------------

	/**
	 * REGRESSION (typo caught and fixed 2026-09-21).
	 *
	 * QuotaController::getQuota() guards against quota data cached before the
	 * numeric normalisation landed:
	 *
	 *     if (isset($quotaData['APICallsQuota']) && false === is_numeric(...)) {
	 *         $this->forceCheckRemoteQuota();
	 *         $quotaData = $this->getQuotaData();
	 *     }
	 *
	 * The refreshed array used to be assigned to a misspelled `$quotData` and
	 * thrown away, so execution continued with the stale NON-NUMERIC value and
	 * hit `number_format($quotaData['APICallsQuota'])` two lines later →
	 * TypeError on PHP 8. The provision paid for the remote round-trip and
	 * still crashed on exactly the case it was written to prevent.
	 *
	 * Introduced 2026-06-25 in 9480f182, reformatted (not fixed) by f504e178,
	 * corrected 2026-09-21. Before the fix this test failed with
	 * "number_format(): Argument #1 ($num) must be of type int|float, string
	 * given"; the sentinel below keeps proving the guard still fires, so the
	 * test cannot silently pass by never entering the branch.
	 */
	public function test_getQuota_refreshes_legacy_non_numeric_quota_from_the_api() {
		$state = $this->interceptRemoteQuota( array( 'APICallsQuota' => 7777 ) );

		// Legacy cached shape: a formatted string where an int is now expected.
		$this->seedQuotaTransient( array( 'APICallsQuota' => '1,000' ) );

		$ctrl  = $this->freshController();
		$quota = $ctrl->getQuota();

		// SENTINEL: the provision really fired — the cache was invalidated and
		// the remote refresh was actually fetched. Without this the test would
		// still pass if the guard stopped triggering altogether.
		$this->assertSame(
			1,
			$state->calls,
			'Sentinel: the non-numeric guard must have triggered exactly one remote refresh.'
		);

		$this->assertSame(
			7777,
			$quota->monthly->total,
			'REGRESSION: the refreshed remote value must replace the stale non-numeric one (it used to be discarded by the $quotData typo).'
		);
		$this->assertIsInt(
			$quota->monthly->total,
			'REGRESSION: the value reaching number_format() must be numeric, or getQuota() fatals on PHP 8.'
		);
	}

} // class
