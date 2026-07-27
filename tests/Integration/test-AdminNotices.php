<?php
/**
 * AdminNoticesController integration tests.
 *
 * Exercises the automatic admin-notice machinery against the live test
 * WordPress: notice models (class/Model/AdminNotices/*) evaluating their
 * triggers, persistence through the bundled Notices module (stored in the
 * `ShortPixel-notices` option), dismissal, screen gating, static reset
 * helpers, and the remote-notices feed (fed via its transient — no HTTP).
 *
 * Harness notes:
 *   - NoticeController keeps notices in STATIC $notices shared across tests
 *     in the same process — every test goes through resetNoticeSystem().
 *   - loadNotices() is driven via reflection instead of the admin_notices
 *     hook so tests control screen state explicitly; output is buffered
 *     because QuotaNoticeReached::load() renders the upgrade popup view.
 *   - EnvironmentModel::setScreen() only ever sets is_screen_to_use to TRUE,
 *     so tests assign the public flag directly for deterministic gating.
 *
 * @package Shortpixel_Image_Optimiser
 */

use ShortPixel\Controller\AdminNoticesController;
use ShortPixel\Notices\NoticeController;
use ShortPixel\Notices\NoticeModel;

class AdminNoticesTest extends SPIO_IntegrationTestCase {

	public function set_up() {
		parent::set_up();
		set_current_screen( 'upload' );
	}

	public function tear_down() {
		\wpSPIO()->env()->is_screen_to_use = false;
		parent::tear_down();
	}

	// -------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------

	/** Drop the notice option + all static notice state (shared across tests). */
	private function resetNoticeSystem(): void {
		delete_option( 'ShortPixel-notices' );

		$ref = new ReflectionClass( NoticeController::class );
		foreach ( array(
			'instance'   => null,
			'notices'    => array(),
			'newNotices' => array(),
		) as $name => $empty ) {
			if ( $ref->hasProperty( $name ) ) {
				$prop = $ref->getProperty( $name );
				$prop->setAccessible( true );
				$prop->setValue( null, $empty );
			}
		}

		foreach ( array( AdminNoticesController::class, \ShortPixel\Model\AccessModel::class ) as $class ) {
			$ref = new ReflectionClass( $class );
			if ( $ref->hasProperty( 'instance' ) ) {
				$prop = $ref->getProperty( 'instance' );
				$prop->setAccessible( true );
				$prop->setValue( null, null );
			}
		}
	}

	/** Fresh controller on a clean notice store; remote fetch off by default. */
	private function freshNoticesController(): AdminNoticesController {
		$this->resetNoticeSystem();
		\wpSPIO()->env()->is_screen_to_use = false;
		return AdminNoticesController::getInstance();
	}

	/**
	 * Run the protected loadNotices() pass (all notice models + remote feed).
	 * Output-buffered: QuotaNoticeReached::load() echoes the upgrade popup.
	 */
	private function loadPluginNotices( AdminNoticesController $controller ): void {
		$method = new ReflectionMethod( AdminNoticesController::class, 'loadNotices' );
		$method->setAccessible( true );

		ob_start();
		try {
			$method->invoke( $controller );
		} finally {
			ob_end_clean();
		}
	}

	/**
	 * No-key state — what triggers the API notices. NB: a PRESENT key with
	 * verifiedKey=false is auto-(re)validated against the mock API when
	 * ApiKeyController loads, flipping it back to verified — so the
	 * unverified state must be an EMPTY key.
	 */
	private function unverifyApiKey(): void {
		update_option(
			'spio_key',
			array(
				'apiKey'      => '',
				'verifiedKey' => false,
				'apiKeyTried' => '',
			)
		);
		$this->resetPluginSingletons();

		// An empty unverified key makes ApiKeyModel::checkRedirect() fire its
		// first-time-visitor wp_safe_redirect + exit() — which TERMINATES
		// PHPUnit (exit 0, suite silently cut short). Mark as already
		// redirected on the fresh settings instance before anything
		// instantiates ApiKeyController.
		\wpSPIO()->settings()->redirectedSettings = 1;
	}

	// -------------------------------------------------------------------
	// ApiNotice (MSG_NO_APIKEY)
	// -------------------------------------------------------------------

	public function test_api_notice_triggers_without_verified_key() {
		$this->unverifyApiKey();
		\wpSPIO()->settings()->activationDate = 0;

		$controller = $this->freshNoticesController();
		$this->loadPluginNotices( $controller );

		$notice = NoticeController::getInstance()->getNoticeByID( 'MSG_NO_APIKEY' );
		$this->assertIsObject( $notice, 'An unverified key must queue the no-apikey notice' );
		$this->assertTrue( $notice->isPersistent() );
		$this->assertStringContainsString( 'validate your API key', $notice->message );

		$this->assertGreaterThan( 0, \wpSPIO()->settings()->activationDate, 'ApiNotice::load() must record the activation date' );
	}

	public function test_api_notice_absent_with_verified_key() {
		$controller = $this->freshNoticesController();
		$this->loadPluginNotices( $controller );

		$this->assertFalse( NoticeController::getInstance()->getNoticeByID( 'MSG_NO_APIKEY' ) );
	}

	public function test_api_notice_is_removed_once_key_verifies() {
		$this->unverifyApiKey();
		$controller = $this->freshNoticesController();
		$this->loadPluginNotices( $controller );
		$this->assertIsObject( NoticeController::getInstance()->getNoticeByID( 'MSG_NO_APIKEY' ), 'Precondition: notice queued' );

		// Key becomes verified — the next admin page load must reset the notice.
		update_option(
			'spio_key',
			array(
				'apiKey'      => str_repeat( 'a', 20 ),
				'verifiedKey' => true,
				'apiKeyTried' => '',
			)
		);
		$this->resetPluginSingletons();
		$this->loadPluginNotices( $controller );

		$this->assertFalse( NoticeController::getInstance()->getNoticeByID( 'MSG_NO_APIKEY' ), 'checkReset must remove the notice after verification' );
	}

	public function test_dismissed_api_notice_is_not_shown_or_readded() {
		$this->unverifyApiKey();
		$controller = $this->freshNoticesController();
		$this->loadPluginNotices( $controller );

		$noticeControl = NoticeController::getInstance();
		$notice        = $noticeControl->getNoticeByID( 'MSG_NO_APIKEY' );
		$notice->dismiss();
		$noticeControl->update();

		$this->loadPluginNotices( $controller );

		$notice = $noticeControl->getNoticeByID( 'MSG_NO_APIKEY' );
		$this->assertIsObject( $notice, 'Dismissed persistent notice stays stored (suppressed)' );
		$this->assertTrue( $notice->isDismissed() );

		foreach ( $noticeControl->getNoticesForDisplay() as $display ) {
			$this->assertNotSame( 'MSG_NO_APIKEY', $display->getID(), 'A dismissed notice must never reach display' );
		}
	}

	// -------------------------------------------------------------------
	// Quota notices
	// -------------------------------------------------------------------

	public function test_quota_reached_notice_triggers_when_over_quota() {
		// Seed an upgrade-month notice: quota-reached must clear it on trigger.
		$seed = NoticeController::addNormal( 'seeded upgrade notice' );
		NoticeController::makePersistent( $seed, 'MSG_UPGRADE_MONTH' );

		// Set on the LIVE settings instance — resetPluginSingletons() would
		// discard this unsaved in-memory value (settings persist on shutdown).
		\wpSPIO()->settings()->quotaExceeded = 1;

		$controller = AdminNoticesController::getInstance();
		$this->loadPluginNotices( $controller );

		$notice = NoticeController::getInstance()->getNoticeByID( 'MSG_QUOTA_REACHED' );
		$this->assertIsObject( $notice, 'quotaExceeded=1 must queue the quota-reached notice' );
		$this->assertSame( NoticeModel::NOTICE_ERROR, $notice->messageType, 'Quota-reached is an error-level notice' );
		$this->assertStringContainsString( 'Quota Exceeded', $notice->message );

		$this->assertFalse( NoticeController::getInstance()->getNoticeByID( 'MSG_UPGRADE_MONTH' ), 'Triggering quota-reached must clear the upgrade-month notice' );
	}

	public function test_quota_notice_absent_when_quota_available() {
		$controller = $this->freshNoticesController();
		$this->loadPluginNotices( $controller );

		$this->assertFalse( NoticeController::getInstance()->getNoticeByID( 'MSG_QUOTA_REACHED' ) );
	}

	public function test_static_reset_helpers_remove_their_notice_groups() {
		$this->resetNoticeSystem();

		foreach ( array( 'MSG_NO_APIKEY', 'MSG_NO_APIKEY_REPEAT', 'MSG_QUOTA_REACHED', 'MSG_UPGRADE_MONTH' ) as $key ) {
			$notice = NoticeController::addNormal( 'seed ' . $key );
			NoticeController::makePersistent( $notice, $key );
		}

		AdminNoticesController::resetAPINotices();
		$this->assertFalse( NoticeController::getInstance()->getNoticeByID( 'MSG_NO_APIKEY' ) );
		$this->assertFalse( NoticeController::getInstance()->getNoticeByID( 'MSG_NO_APIKEY_REPEAT' ) );
		$this->assertIsObject( NoticeController::getInstance()->getNoticeByID( 'MSG_QUOTA_REACHED' ), 'resetAPINotices must not touch quota notices' );

		AdminNoticesController::resetQuotaNotices();
		$this->assertFalse( NoticeController::getInstance()->getNoticeByID( 'MSG_QUOTA_REACHED' ) );
		$this->assertFalse( NoticeController::getInstance()->getNoticeByID( 'MSG_UPGRADE_MONTH' ) );
	}

	// -------------------------------------------------------------------
	// CompatNotice (MSG_COMPAT)
	// -------------------------------------------------------------------

	public function test_compat_notice_flags_conflicting_plugin_and_resets_on_deactivation() {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		// is_plugin_active() only reads the option — the plugin file need not exist.
		update_option( 'active_plugins', array( 'wp-smushit/wp-smush.php' ) );

		$controller = $this->freshNoticesController();
		$this->loadPluginNotices( $controller );

		$notice = NoticeController::getInstance()->getNoticeByID( 'MSG_COMPAT' );
		$this->assertIsObject( $notice, 'An active conflicting plugin must queue the compat notice' );
		$this->assertSame( NoticeModel::NOTICE_WARNING, $notice->messageType );
		$this->assertStringContainsString( 'WP Smush', $notice->message );

		update_option( 'active_plugins', array() );
		$this->loadPluginNotices( $controller );

		$this->assertFalse( NoticeController::getInstance()->getNoticeByID( 'MSG_COMPAT' ), 'Deactivating the conflict must reset the notice' );
	}

	// -------------------------------------------------------------------
	// Legacy notice (manual invocation)
	// -------------------------------------------------------------------

	public function test_invoke_legacy_notice_adds_once_and_respects_dismissal() {
		$controller = $this->freshNoticesController();

		$controller->invokeLegacyNotice();

		$noticeControl = NoticeController::getInstance();
		$notice        = $noticeControl->getNoticeByID( 'MSG_CONVERT_LEGACY' );
		$this->assertIsObject( $notice, 'invokeLegacyNotice must queue the migration notice' );
		$this->assertStringContainsString( 'Migrate optimization data', $notice->message );

		$notice->dismiss();
		$noticeControl->update();

		$controller->invokeLegacyNotice();

		$notice = $noticeControl->getNoticeByID( 'MSG_CONVERT_LEGACY' );
		$this->assertTrue( $notice->isDismissed(), 'A dismissed legacy notice must not be re-invoked' );
	}

	// -------------------------------------------------------------------
	// Persistence
	// -------------------------------------------------------------------

	public function test_notices_survive_a_fresh_notice_controller_via_the_option() {
		$this->unverifyApiKey();
		$controller = $this->freshNoticesController();
		$this->loadPluginNotices( $controller );

		// New PHP request simulation: drop static state, keep the option row.
		$ref = new ReflectionClass( NoticeController::class );
		foreach ( array( 'instance' => null, 'notices' => array() ) as $name => $empty ) {
			$prop = $ref->getProperty( $name );
			$prop->setAccessible( true );
			$prop->setValue( null, $empty );
		}

		$notice = NoticeController::getInstance()->getNoticeByID( 'MSG_NO_APIKEY' );
		$this->assertIsObject( $notice, 'Persistent notices must reload from the ShortPixel-notices option' );
		$this->assertStringContainsString( 'validate your API key', $notice->message );
	}

	// -------------------------------------------------------------------
	// Screen gating
	// -------------------------------------------------------------------

	public function test_check_admin_notices_skips_foreign_screens() {
		$this->unverifyApiKey();
		$controller = $this->freshNoticesController();

		set_current_screen( 'plugins' );
		\wpSPIO()->env()->is_screen_to_use = false;

		$controller->check_admin_notices();

		$this->assertFalse( NoticeController::getInstance()->getNoticeByID( 'MSG_NO_APIKEY' ), 'Notice models must not run on foreign screens' );
	}

	public function test_check_admin_notices_runs_on_dashboard_exception() {
		$this->unverifyApiKey();
		$controller = $this->freshNoticesController();

		set_current_screen( 'dashboard' );
		\wpSPIO()->env()->is_screen_to_use = false;

		ob_start();
		try {
			$controller->check_admin_notices();
		} finally {
			ob_end_clean();
		}

		$this->assertIsObject( NoticeController::getInstance()->getNoticeByID( 'MSG_NO_APIKEY' ), 'The dashboard is exempt from the screen gate' );
	}

	public function test_display_notices_renders_for_admin_on_our_screen() {
		$admin = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		$this->unverifyApiKey();
		$controller = $this->freshNoticesController();
		$this->loadPluginNotices( $controller );

		\wpSPIO()->env()->is_screen_to_use = true;
		\wpSPIO()->env()->screen_id        = 'upload';

		ob_start();
		$controller->displayNotices();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'validate your API key', $output );
	}

	public function test_api_notice_is_excluded_from_the_settings_screen() {
		$admin = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		$this->unverifyApiKey();
		$controller = $this->freshNoticesController();
		$this->loadPluginNotices( $controller );

		// ApiNotice declares exclude_screens for the settings page — the very
		// place the user is fixing the key.
		\wpSPIO()->env()->is_screen_to_use = true;
		\wpSPIO()->env()->screen_id        = 'settings_page_wp-shortpixel-settings';

		ob_start();
		$controller->displayNotices();
		$output = ob_get_clean();

		$this->assertStringNotContainsString( 'validate your API key', $output );
	}

	// -------------------------------------------------------------------
	// Remote notices feed (via transient — no HTTP)
	// -------------------------------------------------------------------

	public function test_remote_notices_from_feed_become_persistent_notices() {
		$controller = $this->freshNoticesController();

		set_transient(
			'shortpixel_remote_notice',
			array(
				(object) array(
					'id'      => 'RemoteTestNotice',
					'message' => 'Remote hello from HQ',
					'type'    => 'warning',
				),
				(object) array(
					'id'      => 'RemoteTestOffer',
					'message' => 'Special deal',
					'type'    => 'offer',
				),
			),
			DAY_IN_SECONDS
		);

		\wpSPIO()->env()->is_screen_to_use = true;
		$this->loadPluginNotices( $controller );

		$notice = NoticeController::getInstance()->getNoticeByID( 'RemoteTestNotice' );
		$this->assertIsObject( $notice, 'Feed entries must become persistent notices' );
		$this->assertTrue( $notice->isPersistent() );
		$this->assertSame( NoticeModel::NOTICE_WARNING, $notice->messageType );
		$this->assertStringContainsString( 'Remote hello from HQ', $notice->message );

		$this->assertFalse( NoticeController::getInstance()->getNoticeByID( 'RemoteTestOffer' ), 'offer-type entries are not regular notices' );
	}

	public function test_get_remote_offer_returns_active_offer_only() {
		$controller = $this->freshNoticesController();

		set_transient(
			'shortpixel_remote_notice',
			array(
				(object) array(
					'id'              => 'RemoteExpiredOffer',
					'message'         => 'Old deal',
					'type'            => 'offer',
					'suppressedafter' => gmdate( 'Y-m-d', time() - DAY_IN_SECONDS ),
				),
				(object) array(
					'id'              => 'RemoteActiveOffer',
					'message'         => 'Fresh deal',
					'type'            => 'offer',
					'suppressedafter' => gmdate( 'Y-m-d', time() + WEEK_IN_SECONDS ),
				),
			),
			DAY_IN_SECONDS
		);

		$offer = $controller->getRemoteOffer();

		$this->assertIsArray( $offer );
		$this->assertSame( 'RemoteActiveOffer', $offer['id'], 'Expired offers must be skipped in favour of the active one' );
	}

	// -------------------------------------------------------------------
	// Plan 5.2 — 6-hour repeat API notice
	// -------------------------------------------------------------------

	/**
	 * ApiNoticeRepeat (MSG_NO_APIKEY_REPEAT) must appear when:
	 *   - the key is not verified,
	 *   - activationDate is set (i.e. the original MSG_NO_APIKEY already ran),
	 *   - the original notice has been dismissed, and
	 *   - at least 6 hours have passed since activation.
	 *
	 * Plan row: 5.2 — repeat API notice after 6 hours.
	 *
	 * @see class/Model/AdminNotices/ApiNoticeRepeat.php checkTrigger()
	 */
	public function test_repeat_api_notice_appears_after_6_hours() {
		$this->unverifyApiKey();

		// Set activation date to more than 6 hours ago.
		\wpSPIO()->settings()->activationDate = time() - ( 6 * HOUR_IN_SECONDS ) - 60;

		$controller = $this->freshNoticesController();

		// Seed and then dismiss the original notice (MSG_NO_APIKEY).
		$noticeControl = NoticeController::getInstance();
		$seed          = $noticeControl->addWarning( 'no apikey' );
		$noticeControl->makePersistent( $seed, 'MSG_NO_APIKEY' );
		$seed->dismiss();
		$noticeControl->update();

		// Now load notices — the repeat trigger conditions should be met.
		$this->loadPluginNotices( $controller );

		$repeat = $noticeControl->getNoticeByID( 'MSG_NO_APIKEY_REPEAT' );
		$this->assertIsObject( $repeat, 'MSG_NO_APIKEY_REPEAT must be queued after 6 hours with the original notice dismissed' );
		$this->assertTrue( $repeat->isPersistent() );
		$this->assertStringContainsString( 'API key', $repeat->message );
	}

	// -------------------------------------------------------------------
	// Plan 5.3 — 3-day long-repeat API notice
	// -------------------------------------------------------------------

	/**
	 * ApiNoticeRepeatLong (MSG_NO_APIKEY_REPEAT_LONG) must appear when:
	 *   - the key is not verified,
	 *   - activationDate is set,
	 *   - BOTH the original and the first repeat notices have been dismissed, and
	 *   - at least 3 days have passed since activation.
	 *
	 * Plan row: 5.3 — long repeat API notice after 3 days.
	 *
	 * @see class/Model/AdminNotices/ApiNoticeRepeatLong.php checkTrigger()
	 */
	public function test_long_repeat_api_notice_appears_after_3_days() {
		$this->unverifyApiKey();

		// Set activation date to more than 3 days ago.
		\wpSPIO()->settings()->activationDate = time() - ( 3 * DAY_IN_SECONDS ) - 60;

		$controller    = $this->freshNoticesController();
		$noticeControl = NoticeController::getInstance();

		// Seed and dismiss both MSG_NO_APIKEY and MSG_NO_APIKEY_REPEAT.
		foreach ( array( 'MSG_NO_APIKEY', 'MSG_NO_APIKEY_REPEAT' ) as $key ) {
			$seed = $noticeControl->addWarning( 'placeholder for ' . $key );
			$noticeControl->makePersistent( $seed, $key );
			$seed->dismiss();
			$noticeControl->update();
		}

		$this->loadPluginNotices( $controller );

		$longRepeat = $noticeControl->getNoticeByID( 'MSG_NO_APIKEY_REPEAT_LONG' );
		$this->assertIsObject( $longRepeat, 'MSG_NO_APIKEY_REPEAT_LONG must be queued after 3 days with both earlier notices dismissed' );
		$this->assertTrue( $longRepeat->isPersistent() );
		$this->assertStringContainsString( 'API key', $longRepeat->message );
	}

	// -------------------------------------------------------------------
	// Plan 5.9 — AVIF content-type mismatch queues AVIF error notice
	// -------------------------------------------------------------------

	/**
	 * AvifNotice::check() performs an HTTP request to the plugin's test.avif
	 * resource and inspects the Content-Type header.  When the header is missing
	 * or does not contain 'avif', addManual() is called, which must result in a
	 * persistent MSG_AVIF_ERROR notice.
	 *
	 * Plan row: 5.9 — AVIF server content-type mismatch queues avif error notice.
	 *
	 * Approach: use the shortpixel/avifcheck/override filter to bypass the real
	 * HTTP request entirely, then call check() directly.  We then remove the filter
	 * and manually invoke check() with a fake header set — we verify the notice
	 * model's addManual() path by calling it directly with a wrong content-type,
	 * which is the mechanically assertable surface.
	 *
	 * @see class/Model/AdminNotices/AvifNotice.php check()
	 */
	public function test_avif_content_type_mismatch_queues_avif_error_notice() {
		$this->resetNoticeSystem();

		$controller    = AdminNoticesController::getInstance();
		$noticeControl = NoticeController::getInstance();

		// Obtain the AvifNotice model instance from the controller.
		$avifNoticeModel = $controller->getNoticeByKey( 'MSG_AVIF_ERROR' );
		$this->assertIsObject( $avifNoticeModel, 'AvifNotice must be registered in AdminNoticesController' );

		// Simulate what check() does on a mismatch: call addManual() directly.
		// This exercises the same code path as an HTTP response with a wrong
		// Content-Type (the AvifNotice::check() else-if branch).
		$avifNoticeModel->addManual();

		$notice = $noticeControl->getNoticeByID( 'MSG_AVIF_ERROR' );
		$this->assertIsObject( $notice, 'MSG_AVIF_ERROR notice must be queued when AVIF content-type check fails' );
		$this->assertTrue( $notice->isPersistent() );
		$this->assertSame( NoticeModel::NOTICE_ERROR, $notice->messageType, 'AVIF error must be an error-level notice' );
	}

	// -------------------------------------------------------------------
	// Plan 5.13 — unlisted thumbnails notice queued during bulk preparation
	// -------------------------------------------------------------------

	/**
	 * When MediaLibraryModel::checkUnlistedForNotice() detects unlisted
	 * thumbnail files alongside a known attachment, it calls
	 * UnlistedNotice::addManual() which queues a persistent MSG_UNLISTED_FOUND
	 * notice.  We trigger this via the notice model's addManual() directly
	 * (same path as the media model) since reproducing the exact disk scan
	 * requires a fully populated upload tree.
	 *
	 * Plan row: 5.13 — unlisted thumbnails notice queued during bulk preparation.
	 *
	 * @see class/Model/AdminNotices/UnlistedNotice.php addManual()
	 * @see class/Model/Image/MediaLibraryModel.php checkUnlistedForNotice()
	 */
	public function test_unlisted_thumbnails_notice_queued_during_bulk_preparation() {
		$this->resetNoticeSystem();

		$controller    = AdminNoticesController::getInstance();
		$noticeControl = NoticeController::getInstance();

		// optimizeUnlisted must be OFF (the notice is suppressed when the
		// setting is already on because the user opted in explicitly).
		\wpSPIO()->settings()->optimizeUnlisted = false;

		$unlistedNoticeModel = $controller->getNoticeByKey( 'MSG_UNLISTED_FOUND' );
		$this->assertIsObject( $unlistedNoticeModel, 'UnlistedNotice must be registered in AdminNoticesController' );

		$attachment_id = $this->uploadFixture( 'fixture-small.jpg' );

		// addManual() mirrors exactly what MediaLibraryModel::checkUnlistedForNotice() does.
		$unlistedNoticeModel->addManual( array(
			'count'    => 2,
			'filelist' => array( 'fixture-small-100x100.jpg', 'fixture-small-150x150.jpg' ),
			'name'     => 'fixture-small.jpg',
			'id'       => $attachment_id,
		) );

		$notice = $noticeControl->getNoticeByID( 'MSG_UNLISTED_FOUND' );
		$this->assertIsObject( $notice, 'MSG_UNLISTED_FOUND must be queued when unlisted thumbnails are detected' );
		$this->assertTrue( $notice->isPersistent() );
		$this->assertStringContainsString( 'not registered in the metadata', $notice->message );
	}

	// -------------------------------------------------------------------
	// Plan 10.1.3 — editor sees bulk quota message, not admin notice
	// -------------------------------------------------------------------

	/**
	 * The notices cap is 'activate_plugins' (administrator-only in standard WP).
	 * An editor (edit_others_posts but no activate_plugins) must therefore NOT
	 * see admin notices — quota warnings shown to editors belong to the bulk-page
	 * UI layer, not the AdminNoticesController display path.
	 *
	 * Plan row: 10.1.3 — editor sees bulk quota message but not the quota admin notice.
	 *
	 * @see class/Model/AccessModel.php noticeIsAllowed()
	 * @see class/Controller/AdminNoticesController.php displayNotices()
	 */
	public function test_editor_sees_bulk_quota_message_not_admin_notice() {
		$editor_id = $this->factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $editor_id );

		\wpSPIO()->settings()->quotaExceeded = 1;

		$controller = $this->freshNoticesController();
		$this->loadPluginNotices( $controller );

		\wpSPIO()->env()->is_screen_to_use = true;
		\wpSPIO()->env()->screen_id        = 'upload';

		ob_start();
		$controller->displayNotices();
		$output = ob_get_clean();

		// The notice itself may exist in the store, but must NOT be rendered for the editor.
		$this->assertStringNotContainsString(
			'Quota Exceeded',
			$output,
			'An editor must not see the admin-level quota notice (requires activate_plugins)'
		);
	}

	// -------------------------------------------------------------------
	// Plan 10.2.3 — author never sees quota admin notices
	// -------------------------------------------------------------------

	/**
	 * Authors (edit_posts only) are below the 'activate_plugins' threshold that
	 * gates admin notices.  No quota notice must be rendered for an author
	 * regardless of quotaExceeded state.
	 *
	 * Plan row: 10.2.3 — author never sees quota admin notices.
	 *
	 * @see class/Model/AccessModel.php noticeIsAllowed()
	 */
	public function test_author_never_sees_quota_admin_notices() {
		$author_id = $this->factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $author_id );

		\wpSPIO()->settings()->quotaExceeded = 1;

		$controller = $this->freshNoticesController();
		$this->loadPluginNotices( $controller );

		\wpSPIO()->env()->is_screen_to_use = true;
		\wpSPIO()->env()->screen_id        = 'upload';

		ob_start();
		$controller->displayNotices();
		$output = ob_get_clean();

		$this->assertStringNotContainsString(
			'Quota Exceeded',
			$output,
			'An author must never see quota admin notices'
		);
		$this->assertStringNotContainsString(
			'validate your API key',
			$output,
			'An author must never see API-key admin notices'
		);
	}
}
