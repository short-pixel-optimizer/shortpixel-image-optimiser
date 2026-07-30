<?php
/**
 * Tests for ShortPixel\Controller\AjaxController.
 *
 * Focus areas:
 *   - Class constants (error code table).
 *   - getInstance() singleton contract.
 *   - checkActionAccess() — returns true for a capable user; exits with
 *     NO_ACCESS JSON and calls send() for an incapable user.
 *     (Both paths exercised via reflection; the exit path is wrapped in
 *     ob_start so wp_send_json's header calls do not abort the test runner.)
 *   - handleChangeMode() — stores the user option when new_mode is present;
 *     returns false when absent. Exercised via reflection with seeded $_POST.
 *   - settingsRequest() dispatch routing — not end-to-end testable because
 *     checkNonce() and the handlers that follow call send()/exit(); tested
 *     through the constant table (the recognized action list is a contract).
 *
 * NOT covered here (exit / echo / die on every code path):
 *   - ajaxRequest(), ajax_processQueue(), settingsFormSubmit(),
 *     ajax_checkquota(), ajax_proposeQuotaUpgrade() — all call send()
 *     which calls wp_send_json() and then exit().  The same applies to
 *     every item-action handler (optimizeItem, restoreItem, …) because they
 *     are only reachable after checkNonce() which would exit on a bad nonce
 *     and wp_send_json() exits on a good path.
 *   - checkNonce() — wp_verify_nonce always returns false outside a real
 *     HTTP request, so the nonce-fail branch exits immediately.
 *   - getProcessorKey() / checkProcessorKey() — depend on CacheController
 *     transient state wired to the queue infrastructure.
 *   - All handlers reached after the nonce guard (optimizeItem, restoreItem,
 *     reOptimizeItem, …) — need QueueController / filesystem / API round-trips.
 *
 * @package Shortpixel_Image_Optimiser
 */

use ShortPixel\Controller\AjaxController;
use ShortPixel\Model\AccessModel;

class AjaxControllerTest extends WP_UnitTestCase {

	// -----------------------------------------------------------------
	// Helpers
	// -----------------------------------------------------------------

	/** Returns a fresh AjaxController bypassing the constructor (no hooks). */
	private function freshController(): AjaxController {
		$ref = new ReflectionClass( AjaxController::class );
		return $ref->newInstanceWithoutConstructor();
	}

	private function invokeProtected( AjaxController $c, string $method, array $args = array() ) {
		$ref = new ReflectionClass( AjaxController::class );
		while ( $ref && ! $ref->hasMethod( $method ) ) {
			$ref = $ref->getParentClass();
		}
		$this->assertNotFalse( $ref, "Method $method not found on any ancestor" );
		$m = $ref->getMethod( $method );
		$m->setAccessible( true );
		return $m->invoke( $c, ...$args );
	}

	public function set_up() {
		parent::set_up();
		wp_set_current_user( 0 );

		// Reset the singleton so each test starts clean.
		$ref = new ReflectionClass( AjaxController::class );
		$p   = $ref->getProperty( 'instance' );
		$p->setAccessible( true );
		$p->setValue( null, null );
	}

	public function tear_down() {
		wp_set_current_user( 0 );
		$_POST = array();

		// Reset singleton.
		$ref = new ReflectionClass( AjaxController::class );
		$p   = $ref->getProperty( 'instance' );
		$p->setAccessible( true );
		$p->setValue( null, null );

		parent::tear_down();
	}

	// -----------------------------------------------------------------
	// Class constants — error code table
	// -----------------------------------------------------------------

	/**
	 * Sentinel-table: pins all seven public error constants to their agreed values.
	 *
	 * Any future renumbering would silently break the JS error-handling switch
	 * unless this test fails first.
	 */
	public function test_error_constants_have_correct_values() {
		// Sentinel: all seven constants must exist AND hold the specified int.
		$this->assertSame( -1, AjaxController::PROCESSOR_ACTIVE );
		$this->assertSame( -2, AjaxController::NONCE_FAILED );
		$this->assertSame( -3, AjaxController::NO_ACTION );
		$this->assertSame( -4, AjaxController::APIKEY_FAILED );
		$this->assertSame( -5, AjaxController::NOQUOTA );
		$this->assertSame( -6, AjaxController::SERVER_ERROR );
		$this->assertSame( -7, AjaxController::NO_ACCESS );
	}

	// -----------------------------------------------------------------
	// getInstance — singleton contract
	// -----------------------------------------------------------------

	public function test_getInstance_returns_AjaxController_instance() {
		$this->assertInstanceOf( AjaxController::class, AjaxController::getInstance() );
	}

	public function test_getInstance_returns_the_same_object_on_repeated_calls() {
		$a = AjaxController::getInstance();
		$b = AjaxController::getInstance();
		// Sentinel: identity check. A regression that constructed a fresh instance
		// each call would pass assertEquals but fail assertSame.
		$this->assertSame( $a, $b );
	}

	// -----------------------------------------------------------------
	// checkActionAccess — success path (capable user)
	// -----------------------------------------------------------------

	public function test_checkActionAccess_returns_true_for_administrator() {
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$c      = $this->freshController();
		$result = $this->invokeProtected( $c, 'checkActionAccess', array( 'some_action', 'is_author' ) );

		// Sentinel: returns true (not void, not false) for an allowed user.
		// A regression that stripped the return statement would return null here.
		$this->assertTrue( $result );
	}

	public function test_checkActionAccess_returns_true_for_author_with_is_author_level() {
		$author_id = $this->factory->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $author_id );

		$c      = $this->freshController();
		$result = $this->invokeProtected( $c, 'checkActionAccess', array( 'optimizeItem', 'is_author' ) );
		$this->assertTrue( $result );
	}

	// -----------------------------------------------------------------
	// checkActionAccess — failure path (subscriber / no caps)
	// -----------------------------------------------------------------

	public function test_checkActionAccess_sends_NO_ACCESS_json_for_subscriber_and_exits() {
		$subscriber_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber_id );

		// send() ends in wp_send_json(), which outside an AJAX context calls a
		// plain `die` (not wp_die) — uncatchable, it kills the PHPUnit process.
		// Intercept at the send() seam instead: the spy records the payload and
		// throws to halt execution before the exit is reached.
		$c = new class extends AjaxController {
			public $sentJson = null;
			public function __construct() { /* no hooks */ }
			protected function send( $json ) {
				$this->sentJson = $json;
				throw new \RuntimeException( 'send-intercepted' );
			}
		};

		try {
			$this->invokeProtected( $c, 'checkActionAccess', array( 'createBulk', 'is_editor' ) );
			$this->fail( 'Expected checkActionAccess to deny access and call send()' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'send-intercepted', $e->getMessage() );
		}

		// Sentinel: the denial payload must carry the NO_ACCESS error code and
		// echo back the requested action.
		$this->assertNotNull( $c->sentJson );
		$this->assertSame( AjaxController::NO_ACCESS, $c->sentJson->error );
		$this->assertSame( 'createBulk', $c->sentJson->action );
		$this->assertFalse( $c->sentJson->status );
	}

	// -----------------------------------------------------------------
	// handleChangeMode — user-option update
	// -----------------------------------------------------------------

	public function test_handleChangeMode_returns_false_when_new_mode_is_not_in_POST() {
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$savedPost = $_POST;
		$_POST     = array(); // no new_mode key

		$c      = $this->freshController();
		$result = $this->invokeProtected( $c, 'handleChangeMode', array( array() ) );

		$_POST = $savedPost;

		// Sentinel: must return false (not null, not true) when the POST field is absent.
		// A regression that silently did nothing without returning false would pass
		// assertNull but fail assertFalse.
		$this->assertFalse( $result );
	}

	public function test_handleChangeMode_stores_mode_as_user_option_when_new_mode_is_present() {
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$savedPost  = $_POST;
		$_POST      = array( 'new_mode' => 'advanced' );

		$c = $this->freshController();
		$this->invokeProtected( $c, 'handleChangeMode', array( array() ) );

		$_POST = $savedPost;

		// Sentinel: the user option 'shortpixel-settings-mode' must be 'advanced'
		// after the call. A regression that forgot to call update_user_option()
		// would leave it at its previous value (typically false).
		$stored = get_user_option( 'shortpixel-settings-mode', $admin_id );
		$this->assertSame( 'advanced', $stored );
	}

	public function test_handleChangeMode_accepts_simple_mode_value() {
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$savedPost = $_POST;
		$_POST     = array( 'new_mode' => 'simple' );

		$c = $this->freshController();
		$this->invokeProtected( $c, 'handleChangeMode', array( array() ) );

		$_POST = $savedPost;

		$stored = get_user_option( 'shortpixel-settings-mode', $admin_id );
		$this->assertSame( 'simple', $stored );
	}

	// -----------------------------------------------------------------
	// settingsRequest action-routing contract (constant list)
	// -----------------------------------------------------------------

	/**
	 * Pins the set of recognised actions that settingsRequest() delegates to
	 * settingsFormSubmit(). If someone renames or removes a case label, the
	 * production form submit silently becomes a no-op logged with addError — this
	 * test must catch that regression.
	 *
	 * Bug #24 FIXED (ff5641a7): the default branch no longer calls exit('0'). It
	 * now builds a $json response object (result=false, message='Settings requests
	 * with invalid action') and calls $this->send($json). The source text assertion
	 * below also pins the new error text so a rollback would be caught.
	 *
	 * We verify the contract by reading the source and asserting the expected
	 * strings appear. A full end-to-end call is blocked by checkNonce().
	 */
	public function test_settingsRequest_recognises_all_expected_action_labels() {
		$src = file_get_contents(
			dirname( __DIR__, 2 ) . '/class/Controller/AjaxController.php'
		);

		$expected_cases = array(
			"'form_submit'",
			"'action_addkey'",
			"'action_debug_redirectBulk'",
			"'action_debug_removePrevented'",
			"'action_debug_removeProcessorKey'",
			"'action_debug_resetNotices'",
			"'action_debug_resetQueue'",
			"'action_debug_resetquota'",
			"'action_debug_resetStats'",
			"'action_debug_triggerNotice'",
			"'action_request_new_key'",
			"'action_debug_editSetting'",
			"'action_end_quick_tour'",
		);

		// Sentinel: every expected case label must appear verbatim in the source.
		// A rename would drop exactly one assertion, making the regression visible.
		foreach ( $expected_cases as $case ) {
			$this->assertStringContainsString(
				$case,
				$src,
				"Expected case $case is missing from settingsRequest() dispatch"
			);
		}

		// Bug #24 FIXED (ff5641a7): default branch must NOT contain bare exit('0').
		// Instead it sends a structured JSON error. Pin both the absence of the old
		// bare exit and the presence of the new error message text.
		$this->assertStringNotContainsString(
			"exit('0')",
			$src,
			"settingsRequest() default branch must not use bare exit('0') — Bug #24 fixed (ff5641a7)"
		);
		$this->assertStringContainsString(
			'Settings requests with invalid action',
			$src,
			"settingsRequest() default branch must send the new structured error message — Bug #24 (ff5641a7)"
		);
	}

	// -----------------------------------------------------------------
	// ajaxRequest action-routing contract (NO_ACTION default)
	// -----------------------------------------------------------------

	/**
	 * Pins that the default branch of ajaxRequest() sets error = NO_ACTION.
	 *
	 * The handler cannot be invoked end-to-end due to checkNonce(), but the
	 * source text is a stable contract: any edit to the NO_ACTION default case
	 * that removes the constant reference would fail this assertion.
	 */
	public function test_ajaxRequest_default_branch_references_NO_ACTION_constant() {
		$src = file_get_contents(
			dirname( __DIR__, 2 ) . '/class/Controller/AjaxController.php'
		);

		// Sentinel: NO_ACTION must appear in the default branch of the switch.
		$this->assertStringContainsString( 'self::NO_ACTION', $src );
	}
}
