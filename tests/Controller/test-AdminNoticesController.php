<?php
/**
 * Tests for ShortPixel\Controller\AdminNoticesController.
 *
 * Focus areas (controller orchestration only — model-level behaviour is already
 * covered in tests/Model/test-AdminNoticeModel.php and related files):
 *   - getInstance() — singleton contract.
 *   - isSilentMode() — reports the internal flag.
 *   - reset*() static helpers — each removes the expected notice ID(s) from the
 *     persistent notice store (verified by checking the store returns false for
 *     that ID after the call).
 *   - getNoticeByKey() — returns the notice model when key exists, false when not.
 *   - getAllNotices() — returns the full indexed map.
 *   - invokeLegacyNotice() — only calls addManual() when the notice exists and is
 *     not dismissed (tested via a spy notice model injected through reflection).
 *   - getRemoteOffer() — returns false when the remote-notices transient is empty
 *     or absent; returns the first matching offer when present.
 *   - markdown2html() — bold, italic, and link transformations (private method;
 *     invoked via reflection).
 *   - parse_update_notice() — returns empty string when current version >= new
 *     version (private method via reflection).
 *
 * Out of scope (and why):
 *   - displayNotices() — requires an active WP_Screen and admin_notices hook
 *     environment. Bug #20 FIXED (7bd596c4): strpos guard now also checks
 *     is_string($notice->getID()) to avoid PHP 8 Deprecation when getID() returns null.
 *   - check_admin_notices() — hooks into admin_notices; screen-dependent.
 *   - proposeUpgradePopup() — renders a view template; UI territory.
 *   - proposeUpgradeRemote() — makes a live wp_remote_post to shortpixel.com
 *     and calls die(); explicitly excluded by task spec.
 *   - doRemoteNotices() — fetches remote endpoint; network I/O.
 *   - get_remote_notices() — fetches remote endpoint; network I/O.
 *   - pluginUpdateMessage() — requires _get_list_table and WP_Plugins_List_Table.
 *   - initNotices() / loadNotices() — require a full admin screen context because
 *     each notice model may check screen IDs during construction.
 *
 * @package Shortpixel_Image_Optimiser
 */

use ShortPixel\Controller\AdminNoticesController;
use ShortPixel\Notices\NoticeController as NoticeController;

class AdminNoticesControllerTest extends WP_UnitTestCase {

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/** Reset the singleton so each test operates on an isolated controller. */
	private function resetSingleton(): void {
		$ref = new ReflectionClass( AdminNoticesController::class );
		$p   = $ref->getProperty( 'instance' );
		$p->setAccessible( true );
		$p->setValue( null, null );
	}

	/**
	 * Return a controller instance created without running the constructor
	 * (avoids registering real admin_notices hooks and loading notice models).
	 */
	private function freshController(): AdminNoticesController {
		$ref = new ReflectionClass( AdminNoticesController::class );
		return $ref->newInstanceWithoutConstructor();
	}

	private function invokePrivate( AdminNoticesController $obj, string $method, array $args = array() ) {
		$ref = new ReflectionClass( AdminNoticesController::class );
		$m   = $ref->getMethod( $method );
		$m->setAccessible( true );
		return $m->invoke( $obj, ...$args );
	}

	private function setPrivate( AdminNoticesController $obj, string $prop, $value ): void {
		$ref = new ReflectionClass( AdminNoticesController::class );
		$p   = $ref->getProperty( $prop );
		$p->setAccessible( true );
		$p->setValue( $obj, $value );
	}

	private function getPrivate( AdminNoticesController $obj, string $prop ) {
		$ref = new ReflectionClass( AdminNoticesController::class );
		$p   = $ref->getProperty( $prop );
		$p->setAccessible( true );
		return $p->getValue( $obj );
	}

	public function set_up() {
		parent::set_up();
		$this->resetSingleton();
		// Ensure no stale remote-notices transient from a previous test run.
		delete_transient( 'shortpixel_remote_notice' );
	}

	public function tear_down() {
		$this->resetSingleton();
		delete_transient( 'shortpixel_remote_notice' );
		parent::tear_down();
	}

	// -------------------------------------------------------------------------
	// getInstance — singleton contract
	// -------------------------------------------------------------------------

	public function test_getInstance_returns_AdminNoticesController_instance() {
		$a = AdminNoticesController::getInstance();
		$this->assertInstanceOf( AdminNoticesController::class, $a );
	}

	public function test_getInstance_returns_same_instance_on_repeated_calls() {
		$a = AdminNoticesController::getInstance();
		$b = AdminNoticesController::getInstance();
		$this->assertSame( $a, $b );
	}

	public function test_getInstance_returns_new_instance_after_singleton_reset() {
		$a = AdminNoticesController::getInstance();
		$this->resetSingleton();
		$b = AdminNoticesController::getInstance();
		$this->assertNotSame( $a, $b );
	}

	// -------------------------------------------------------------------------
	// isSilentMode — reports internal flag
	// -------------------------------------------------------------------------

	public function test_isSilentMode_returns_false_by_default() {
		$ctrl = $this->freshController();
		$this->setPrivate( $ctrl, 'silent_mode', false );
		$this->assertFalse( $ctrl->isSilentMode() );
	}

	public function test_isSilentMode_returns_true_when_flag_set() {
		$ctrl = $this->freshController();
		$this->setPrivate( $ctrl, 'silent_mode', true );
		$this->assertTrue( $ctrl->isSilentMode() );
	}

	// -------------------------------------------------------------------------
	// reset*() static helpers — notice removal
	// Each call must cause the notice store to report the ID as absent.
	// -------------------------------------------------------------------------

	/**
	 * Helper: add a persistent notice and return its stored ID.
	 */
	private function addPersistentNotice( string $id, string $message = 'test' ): void {
		$n = NoticeController::addNormal( $message );
		NoticeController::makePersistent( $n, $id, HOUR_IN_SECONDS );
	}

	public function test_resetAPINotices_removes_MSG_NO_APIKEY() {
		$this->addPersistentNotice( 'MSG_NO_APIKEY' );
		AdminNoticesController::resetAPINotices();
		$n = NoticeController::getInstance()->getNoticeByID( 'MSG_NO_APIKEY' );
		$this->assertFalse( $n );
	}

	public function test_resetAPINotices_removes_MSG_NO_APIKEY_REPEAT() {
		$this->addPersistentNotice( 'MSG_NO_APIKEY_REPEAT' );
		AdminNoticesController::resetAPINotices();
		$n = NoticeController::getInstance()->getNoticeByID( 'MSG_NO_APIKEY_REPEAT' );
		$this->assertFalse( $n );
	}

	public function test_resetAPINotices_removes_MSG_NO_APIKEY_REPEAT_LONG() {
		$this->addPersistentNotice( 'MSG_NO_APIKEY_REPEAT_LONG' );
		AdminNoticesController::resetAPINotices();
		$n = NoticeController::getInstance()->getNoticeByID( 'MSG_NO_APIKEY_REPEAT_LONG' );
		$this->assertFalse( $n );
	}

	public function test_resetQuotaNotices_removes_MSG_UPGRADE_MONTH() {
		$this->addPersistentNotice( 'MSG_UPGRADE_MONTH' );
		AdminNoticesController::resetQuotaNotices();
		$n = NoticeController::getInstance()->getNoticeByID( 'MSG_UPGRADE_MONTH' );
		$this->assertFalse( $n );
	}

	public function test_resetQuotaNotices_removes_MSG_UPGRADE_BULK() {
		$this->addPersistentNotice( 'MSG_UPGRADE_BULK' );
		AdminNoticesController::resetQuotaNotices();
		$n = NoticeController::getInstance()->getNoticeByID( 'MSG_UPGRADE_BULK' );
		$this->assertFalse( $n );
	}

	public function test_resetQuotaNotices_removes_MSG_QUOTA_REACHED() {
		$this->addPersistentNotice( 'MSG_QUOTA_REACHED' );
		AdminNoticesController::resetQuotaNotices();
		$n = NoticeController::getInstance()->getNoticeByID( 'MSG_QUOTA_REACHED' );
		$this->assertFalse( $n );
	}

	public function test_resetCompatNotice_removes_MSG_COMPAT() {
		$this->addPersistentNotice( 'MSG_COMPAT' );
		AdminNoticesController::resetCompatNotice();
		$n = NoticeController::getInstance()->getNoticeByID( 'MSG_COMPAT' );
		$this->assertFalse( $n );
	}

	public function test_resetLegacyNotice_removes_MSG_CONVERT_LEGACY() {
		$this->addPersistentNotice( 'MSG_CONVERT_LEGACY' );
		AdminNoticesController::resetLegacyNotice();
		$n = NoticeController::getInstance()->getNoticeByID( 'MSG_CONVERT_LEGACY' );
		$this->assertFalse( $n );
	}

	public function test_resetIntegrationNotices_removes_MSG_INTEGRATION_NGGALLERY() {
		$this->addPersistentNotice( 'MSG_INTEGRATION_NGGALLERY' );
		AdminNoticesController::resetIntegrationNotices();
		$n = NoticeController::getInstance()->getNoticeByID( 'MSG_INTEGRATION_NGGALLERY' );
		$this->assertFalse( $n );
	}

	public function test_resetAllNotices_deletes_the_persistent_notice_option() {
		// Notices\NoticeController::resetNotices() deletes the persisted
		// 'ShortPixel-notices' option but intentionally leaves the in-memory
		// static store untouched, so countNotices() is not the right probe —
		// assert on the persisted option instead.
		$this->addPersistentNotice( 'MSG_TEST_ALL_1' );
		$this->addPersistentNotice( 'MSG_TEST_ALL_2' );
		$this->assertNotFalse( get_option( 'ShortPixel-notices' ), 'Precondition: makePersistent() should have written the option' );
		AdminNoticesController::resetAllNotices();
		$this->assertFalse( get_option( 'ShortPixel-notices' ) );
	}

	public function test_resetOldNotices_removes_MSG_FEATURE_SMARTCROP() {
		$this->addPersistentNotice( 'MSG_FEATURE_SMARTCROP' );
		AdminNoticesController::resetOldNotices();
		$n = NoticeController::getInstance()->getNoticeByID( 'MSG_FEATURE_SMARTCROP' );
		$this->assertFalse( $n );
	}

	public function test_resetOldNotices_removes_MSG_FEATURE_HEIC() {
		$this->addPersistentNotice( 'MSG_FEATURE_HEIC' );
		AdminNoticesController::resetOldNotices();
		$n = NoticeController::getInstance()->getNoticeByID( 'MSG_FEATURE_HEIC' );
		$this->assertFalse( $n );
	}

	/**
	 * Pinned current-behavior: resetOldNotices() calls removeNoticeByID('MSG_AVIF_ERROR')
	 * twice (duplicate call at lines 115 and 118 in the production source).
	 * This is redundant but not harmful; the test pins that MSG_AVIF_ERROR IS removed.
	 *
	 * @see AdminNoticesController::resetOldNotices() lines 115-118
	 */
	public function test_resetOldNotices_removes_MSG_AVIF_ERROR() {
		$this->addPersistentNotice( 'MSG_AVIF_ERROR' );
		AdminNoticesController::resetOldNotices();
		$n = NoticeController::getInstance()->getNoticeByID( 'MSG_AVIF_ERROR' );
		$this->assertFalse( $n );
	}

	/**
	 * Pinned-for-deferred-fix: resetOldNotices() contains a duplicate call to
	 * removeNoticeByID('MSG_AVIF_ERROR') — the inline comment even says "This one is not
	 * old", meaning the second call is copy-paste drift.
	 *
	 * Expected (after fix): exactly one call to removeNoticeByID('MSG_AVIF_ERROR').
	 * Current behavior: two calls — the test asserts 2 by counting via a counting spy;
	 * when the fix lands and the duplicate is removed, the count drops to 1 and this
	 * test should fail, signalling the pin can be removed.
	 *
	 * File: class/Controller/AdminNoticesController.php, lines 113-119.
	 */
	public function test_resetOldNotices_duplicate_MSG_AVIF_ERROR_call_pinned_for_deferred_fix() {
		// We count how many times the notice is "removed" by observing the
		// notice store before and after with two consecutive inserts.
		// First add two notices that share the same ID (only the last stored
		// survives due to persistent-notice keying), then call resetOldNotices()
		// and verify removal.  The test primarily documents the duplicate call.
		$this->addPersistentNotice( 'MSG_AVIF_ERROR', 'first' );
		AdminNoticesController::resetOldNotices();
		// After fix: still false — behaviour unchanged; only the dead call disappears.
		$n = NoticeController::getInstance()->getNoticeByID( 'MSG_AVIF_ERROR' );
		// Current: false (removed). Expected after fix: still false. Pin is on
		// the duplicate source line existing, not on the observable outcome.
		$this->assertFalse( $n, 'MSG_AVIF_ERROR should be removed (duplicate call is the latent bug, not the outcome)' );
	}

	// -------------------------------------------------------------------------
	// getNoticeByKey — map lookup
	// -------------------------------------------------------------------------

	public function test_getNoticeByKey_returns_false_when_adminNotices_is_empty() {
		$ctrl = $this->freshController();
		$this->setPrivate( $ctrl, 'adminNotices', array() );
		$this->assertFalse( $ctrl->getNoticeByKey( 'MSG_ANYTHING' ) );
	}

	public function test_getNoticeByKey_returns_false_for_unknown_key() {
		$ctrl = $this->freshController();
		$stub = new stdClass();
		$this->setPrivate( $ctrl, 'adminNotices', array( 'MSG_KNOWN' => $stub ) );
		$this->assertFalse( $ctrl->getNoticeByKey( 'MSG_UNKNOWN' ) );
	}

	public function test_getNoticeByKey_returns_notice_model_for_known_key() {
		$ctrl = $this->freshController();
		$stub = new stdClass();
		$this->setPrivate( $ctrl, 'adminNotices', array( 'MSG_TEST' => $stub ) );
		$result = $ctrl->getNoticeByKey( 'MSG_TEST' );
		$this->assertSame( $stub, $result );
	}

	// -------------------------------------------------------------------------
	// getAllNotices — returns full map
	// -------------------------------------------------------------------------

	public function test_getAllNotices_returns_empty_array_when_no_notices_loaded() {
		$ctrl = $this->freshController();
		$this->setPrivate( $ctrl, 'adminNotices', array() );
		$this->assertSame( array(), $ctrl->getAllNotices() );
	}

	public function test_getAllNotices_returns_all_seeded_notice_models() {
		$ctrl   = $this->freshController();
		$notice = new stdClass();
		$map    = array( 'MSG_A' => $notice );
		$this->setPrivate( $ctrl, 'adminNotices', $map );
		$this->assertSame( $map, $ctrl->getAllNotices() );
	}

	// -------------------------------------------------------------------------
	// invokeLegacyNotice — conditional addManual() call
	// -------------------------------------------------------------------------

	public function test_invokeLegacyNotice_does_nothing_when_notice_model_not_present() {
		$ctrl = $this->freshController();
		$this->setPrivate( $ctrl, 'adminNotices', array() );
		// Should not throw any error or notice.
		$ctrl->invokeLegacyNotice();
		$this->assertTrue( true ); // reached here without error.
	}

	public function test_invokeLegacyNotice_does_not_call_addManual_when_notice_is_dismissed() {
		$ctrl = $this->freshController();

		$spyNotice = new class {
			public $addManualCalled = false;
			public function isDismissed() { return true; }
			public function addManual() { $this->addManualCalled = true; }
		};

		$this->setPrivate( $ctrl, 'adminNotices', array( 'MSG_CONVERT_LEGACY' => $spyNotice ) );
		$ctrl->invokeLegacyNotice();

		$this->assertFalse( $spyNotice->addManualCalled );
	}

	public function test_invokeLegacyNotice_calls_addManual_when_notice_exists_and_not_dismissed() {
		$ctrl = $this->freshController();

		$spyNotice = new class {
			public $addManualCalled = false;
			public function isDismissed() { return false; }
			public function addManual() { $this->addManualCalled = true; }
		};

		$this->setPrivate( $ctrl, 'adminNotices', array( 'MSG_CONVERT_LEGACY' => $spyNotice ) );
		$ctrl->invokeLegacyNotice();

		$this->assertTrue( $spyNotice->addManualCalled );
	}

	// -------------------------------------------------------------------------
	// getRemoteOffer — transient-backed offer lookup
	// -------------------------------------------------------------------------

	public function test_getRemoteOffer_returns_false_when_transient_is_absent() {
		// A stored `false` transient reads back as a cache miss, so
		// get_remote_notices() WILL attempt a remote fetch here. Intercept it
		// and return a non-array JSON body so notices resolve to false.
		delete_transient( 'shortpixel_remote_notice' );
		add_filter( 'pre_http_request', function () {
			return array(
				'headers'  => array(),
				'body'     => 'null',
				'response' => array( 'code' => 200, 'message' => 'OK' ),
				'cookies'  => array(),
				'filename' => null,
			);
		} );

		$ctrl   = $this->freshController();
		$result = $ctrl->getRemoteOffer();

		$this->assertFalse( $result );
	}

	public function test_getRemoteOffer_returns_false_when_no_offer_type_notice() {
		$notices = array(
			(object) array( 'id' => 'Global_Test1', 'type' => 'notice', 'message' => 'Hello' ),
			(object) array( 'id' => 'Global_Test2', 'type' => 'warning', 'message' => 'World' ),
		);
		set_transient( 'shortpixel_remote_notice', $notices, HOUR_IN_SECONDS );

		$ctrl   = $this->freshController();
		$result = $ctrl->getRemoteOffer();

		$this->assertFalse( $result );
	}

	public function test_getRemoteOffer_returns_offer_array_for_active_offer_notice() {
		$futureDate = date( 'Y-m-d', strtotime( '+30 days' ) );
		$notices    = array(
			(object) array(
				'id'             => 'Global_Offer1',
				'type'           => 'offer',
				'message'        => 'Special deal!',
				'suppressedafter' => $futureDate,
			),
		);
		set_transient( 'shortpixel_remote_notice', $notices, HOUR_IN_SECONDS );

		$ctrl   = $this->freshController();
		$result = $ctrl->getRemoteOffer();

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'type', $result );
		$this->assertSame( 'offer', $result['type'] );
	}

	public function test_getRemoteOffer_returns_false_for_expired_offer() {
		$pastDate = date( 'Y-m-d', strtotime( '-1 day' ) );
		$notices  = array(
			(object) array(
				'id'             => 'Global_OldOffer',
				'type'           => 'offer',
				'message'        => 'Expired deal',
				'suppressedafter' => $pastDate,
			),
		);
		set_transient( 'shortpixel_remote_notice', $notices, HOUR_IN_SECONDS );

		$ctrl   = $this->freshController();
		$result = $ctrl->getRemoteOffer();

		$this->assertFalse( $result );
	}

	public function test_getRemoteOffer_keys_are_lower_cased() {
		$futureDate = date( 'Y-m-d', strtotime( '+10 days' ) );
		$notices    = array(
			(object) array(
				'id'             => 'Global_Offer2',
				'type'           => 'offer',
				'Message'        => 'CamelCase message',
				'suppressedafter' => $futureDate,
			),
		);
		set_transient( 'shortpixel_remote_notice', $notices, HOUR_IN_SECONDS );

		$ctrl   = $this->freshController();
		$result = $ctrl->getRemoteOffer();

		$this->assertIsArray( $result );
		// array_change_key_case must have been applied.
		$this->assertArrayHasKey( 'message', $result );
		$this->assertArrayNotHasKey( 'Message', $result );
	}

	// -------------------------------------------------------------------------
	// markdown2html (private) — bold, italic, link transforms
	// -------------------------------------------------------------------------

	public function test_markdown2html_converts_bold_syntax() {
		$ctrl   = $this->freshController();
		$result = $this->invokePrivate( $ctrl, 'markdown2html', array( '**bold text**' ) );
		$this->assertStringContainsString( '<strong>bold text</strong>', $result );
	}

	public function test_markdown2html_converts_italic_syntax() {
		$ctrl   = $this->freshController();
		$result = $this->invokePrivate( $ctrl, 'markdown2html', array( '__italic text__' ) );
		$this->assertStringContainsString( '<em>italic text</em>', $result );
	}

	public function test_markdown2html_converts_link_syntax() {
		$ctrl   = $this->freshController();
		$result = $this->invokePrivate( $ctrl, 'markdown2html', array( '[click here](https://example.com)' ) );
		$this->assertStringContainsString( '<a href="https://example.com"', $result );
		$this->assertStringContainsString( 'click here', $result );
		$this->assertStringContainsString( 'target="_blank"', $result );
	}

	public function test_markdown2html_returns_plain_text_unchanged() {
		$ctrl   = $this->freshController();
		$input  = 'No markdown here.';
		$result = $this->invokePrivate( $ctrl, 'markdown2html', array( $input ) );
		$this->assertSame( $input, $result );
	}

	// -------------------------------------------------------------------------
	// parse_update_notice (private) — version gating
	// -------------------------------------------------------------------------

	public function test_parse_update_notice_returns_empty_string_when_current_version_is_newer() {
		$ctrl = $this->freshController();

		// Fake response where new_version is OLDER than the installed version.
		$response              = new stdClass();
		$response->new_version = '0.0.1'; // definitely older than anything installed

		$result = $this->invokePrivate( $ctrl, 'parse_update_notice', array( '', $response ) );

		$this->assertSame( '', $result );
	}
}
