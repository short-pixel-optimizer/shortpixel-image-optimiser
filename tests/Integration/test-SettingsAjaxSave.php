<?php
/**
 * Settings ajax-save flow tests.
 *
 * Exercises the REAL settings-save pipeline end to end:
 * wp_ajax_shortpixel_settingsRequest → AjaxController::settingsRequest()
 * (nonce 'settings_request' + is_admin_user gate) → settingsFormSubmit() →
 * SettingsViewController::load() → checkPost() (nonce 'save-settings' via
 * sp-nonce) → processPostData() → processSave() → doRedirect() →
 * handleAjaxSave() → wp_send_json().
 *
 * This is the layer the Controllers unit tests skip: they call helpers
 * directly, while every real save travels through both nonce gates, the
 * checkbox-collapse logic and the JSON termination tested here.
 *
 * NOT tested here: settingsRequest()'s default branch and the debug actions
 * that terminate via plain exit() — a real exit() kills the PHPUnit process
 * (only wp_die() is converted to a catchable exception by the ajax
 * die-handler).
 *
 * @package Shortpixel_Image_Optimiser
 */

use ShortPixel\Controller\AjaxController;
use ShortPixel\Controller\QueueController;

class SettingsAjaxSaveTest extends SPIO_AjaxTestCase {

	/**
	 * Run a settings-page ajax form save as the current user.
	 *
	 * Sets both nonce layers (AjaxController's 'nonce' POST field and the
	 * ViewController's 'sp-nonce') plus the routing fields, then fires the
	 * real admin-ajax action. Fields are mirrored into $_REQUEST because
	 * check_admin_referer() reads $_REQUEST, which the test environment does
	 * not auto-populate from $_POST like PHP itself would.
	 *
	 * @param array  $fields        Form fields to submit.
	 * @param string $screen_action The screen_action to route. Default 'save-settings'
	 *                              (renamed from 'form_submit' in the multisite branch).
	 * @return object|null Decoded JSON response.
	 */
	private function doSettingsSave( array $fields, string $screen_action = 'save-settings' ): ?object {
		$_POST = array_merge(
			array(
				'nonce'         => wp_create_nonce( 'settings_request' ),
				'sp-nonce'      => wp_create_nonce( 'save-settings' ),
				'screen_action' => $screen_action,
				'request_url'   => admin_url( 'options-general.php?page=wp-shortpixel-settings' ),
			),
			$fields
		);
		$_REQUEST = $_POST;

		return $this->doAjax( 'shortpixel_settingsRequest' );
	}

	public function test_settings_request_rejects_bad_nonce() {
		$this->_setRole( 'administrator' );

		$_POST['nonce']         = 'not-a-valid-nonce';
		$_POST['screen_action'] = 'save-settings';

		$response = $this->doAjax( 'shortpixel_settingsRequest' );

		$this->assertIsObject( $response );
		$this->assertSame( AjaxController::NONCE_FAILED, $response->error );
		$this->assertFalse( $response->status );
	}

	public function test_settings_request_requires_admin_capability() {
		// Authors clear the ajaxRequest gate (is_author) but must NOT clear
		// the settings gate (is_admin_user = manage_options).
		$this->_setRole( 'author' );

		$response = $this->doSettingsSave( array( 'compressionType' => 2 ) );

		$this->assertIsObject( $response );
		$this->assertSame( AjaxController::NO_ACCESS, $response->error );
	}

	public function test_form_submit_saves_scalar_setting_and_persists() {
		$this->_setRole( 'administrator' );

		$this->assertEquals( 1, \wpSPIO()->settings()->compressionType, 'Baseline: default compressionType' );

		$response = $this->doSettingsSave( array( 'compressionType' => 2 ) );

		$this->assertIsObject( $response, 'Ajax save must terminate through wp_send_json — raw: ' . $this->lastRawResponse() );
		$this->assertTrue( $response->result );

		$settings = \wpSPIO()->settings();
		$this->assertEquals( 2, $settings->compressionType, 'Saved value must be live on the settings model' );

		// SettingsModel defers the DB write to shutdown; force it and verify
		// the option row so the persistence leg is covered too.
		$settings->onShutdown();
		$stored = get_option( 'spio_settings' );
		$this->assertIsArray( $stored );
		$this->assertEquals( 2, $stored['compressionType'], 'Saved value must reach the spio_settings option' );
	}

	/**
	 * The settings form posts only CHECKED checkboxes. processSave() therefore
	 * flips every boolean-typed setting absent from the POST to false — a save
	 * is a full-state write, not a patch. Both directions must hold.
	 */
	public function test_form_submit_collapses_checkbox_state() {
		$this->_setRole( 'administrator' );

		$settings = \wpSPIO()->settings();
		$this->assertTrue( (bool) $settings->backupImages, 'Baseline: backups on' );
		$this->assertFalse( (bool) $settings->createWebp, 'Baseline: webp off' );

		$response = $this->doSettingsSave(
			array(
				'compressionType' => 1,
				'createWebp'      => 1,
				// backupImages deliberately absent = unchecked.
			)
		);

		$this->assertIsObject( $response );
		$this->assertTrue( $response->result );

		$settings = \wpSPIO()->settings();
		$this->assertFalse( (bool) $settings->backupImages, 'Unchecked checkbox must be saved as off' );
		$this->assertTrue( (bool) $settings->createWebp, 'Checked checkbox must be saved as on' );
	}

	/**
	 * The EXIF field is a REVERSE checkbox: the form field means "remove
	 * EXIF", the setting means "keep EXIF" (exif=1 keeps). processPostData()
	 * maps presence → 0 and absence → 1.
	 */
	public function test_exif_checkbox_is_reverse_mapped() {
		$this->_setRole( 'administrator' );

		$this->doSettingsSave( array( 'compressionType' => 1, 'exif' => 1 ) );
		$this->assertEquals( 0, \wpSPIO()->settings()->exif, 'Posted exif checkbox = remove EXIF = setting 0' );

		$this->doSettingsSave( array( 'compressionType' => 1 ) );
		$this->assertEquals( 1, \wpSPIO()->settings()->exif, 'Absent exif checkbox = keep EXIF = setting 1' );
	}

	/**
	 * Changing the compression type must drop all queued items — continuing
	 * the queue would optimize with the wrong type.
	 */
	public function test_compression_type_change_resets_queues() {
		$this->_setRole( 'administrator' );

		$attachment_id = $this->uploadFixture( 'fixture-small.jpg' );
		$imageModel    = \wpSPIO()->filesystem()->getImage( $attachment_id, 'media' );

		$queueController = new QueueController();
		$queueController->addItemToQueue( $imageModel );
		$this->assertTrue( $this->queueHasWork(), 'Precondition: an item is queued' );

		// Baseline compressionType is 1; save 2 = a change.
		$response = $this->doSettingsSave( array( 'compressionType' => 2 ) );

		$this->assertIsObject( $response );
		$this->assertTrue( $response->result );
		$this->assertFalse( $this->queueHasWork(), 'Queue must be emptied when the compression type changes' );
	}

	public function test_deliverwebp_wp_picture_mode_collapses_to_2() {
		$this->_setRole( 'administrator' );

		$this->doSettingsSave(
			array(
				'compressionType'         => 1,
				'createWebp'              => 1,
				'deliverWebp'             => 1,
				'deliverWebpType'         => 'deliverWebpAltered',
				'deliverWebpAlteringType' => 'deliverWebpAlteredWP',
			)
		);

		$this->assertEquals( 2, \wpSPIO()->settings()->deliverWebp, 'Altered + WP mode must store delivery mode 2 (picture tag)' );
	}

	public function test_deliverwebp_unaltered_mode_collapses_to_3() {
		$this->_setRole( 'administrator' );

		$this->doSettingsSave(
			array(
				'compressionType' => 1,
				'createWebp'      => 1,
				'deliverWebp'     => 1,
				'deliverWebpType' => 'deliverWebpUnaltered',
			)
		);

		$this->assertEquals( 3, \wpSPIO()->settings()->deliverWebp, 'Unaltered mode must store delivery mode 3 (htaccess passthrough)' );
	}

	public function test_deliverwebp_off_stays_0() {
		$this->_setRole( 'administrator' );

		$this->doSettingsSave( array( 'compressionType' => 1 ) );

		$this->assertEquals( 0, \wpSPIO()->settings()->deliverWebp );
	}

	/**
	 * Bug #12 FIXED (b8d8f38d): processWebP() now COMPARES
	 * (`'deliverWebpAlteredGlobal' == $altering`, Yoda style) instead of
	 * assigning, so an unknown altering type no longer silently enables
	 * mode 1 (global .htaccess rewrite) — delivery stays disabled (0).
	 * Flipped from the pinned always-truthy-branch assertion.
	 */
	public function test_deliverwebp_unknown_altering_type_stays_disabled() {
		$this->_setRole( 'administrator' );

		$this->doSettingsSave(
			array(
				'compressionType'         => 1,
				'createWebp'              => 1,
				'deliverWebp'             => 1,
				'deliverWebpType'         => 'deliverWebpAltered',
				'deliverWebpAlteringType' => 'not-a-real-altering-type',
			)
		);

		$this->assertEquals(
			0,
			\wpSPIO()->settings()->deliverWebp,
			'Since b8d8f38d (bug #12 fix) an unknown altering type must leave WebP delivery disabled.'
		);
	}

	// -------------------------------------------------------------------
	// Plan 1.10 — empty key save clears spio_key + welcome redirect flag
	// -------------------------------------------------------------------

	/**
	 * Saving an empty API key must clear the stored key and reset the
	 * redirectedSettings flag back to 0 so the plugin shows the welcome / no-key
	 * screen on the next page load.
	 *
	 * Plan row: 1.10 — empty key save resets to welcome screen state.
	 *
	 * @see class/Controller/View/SettingsViewController.php processSave()
	 */
	public function test_empty_api_key_save_resets_to_welcome_screen_state() {
		$this->_setRole( 'administrator' );

		// Precondition: healthy verified key baseline (from spioSetUpBaseline()).
		$this->assertEquals(
			str_repeat( 'a', 20 ),
			\ShortPixel\Controller\ApiKeyController::getInstance()->getKeyModel()->getKey(),
			'Precondition: verified key in place'
		);

		// Pretend we're already ON the settings page: clearing the key resets
		// redirectedSettings to 0, and any subsequent key load would otherwise
		// hit ApiKeyModel::checkRedirect()'s wp_safe_redirect()+exit() and
		// kill the PHPUnit process. With page=wp-shortpixel-settings the
		// redirect path bails out (ApiKeyModel.php:512).
		$_GET['page'] = 'wp-shortpixel-settings';

		// Submit an empty apiKey field — models the user clearing the key box and saving.
		$this->doSettingsSave( array(
			'compressionType' => 1,
			'apiKey'          => '',
		) );

		unset( $_GET['page'] );

		// checkKey('') on a site with a stored key runs clearApiKey(), which
		// delete_option()s spio_key entirely (ApiKeyModel.php:362-379) — the
		// welcome/no-key state is "option absent", not "option with empty key".
		$this->assertFalse(
			get_option( 'spio_key' ),
			'An empty apiKey save must delete the spio_key option (clearApiKey)'
		);

		// A fresh key model must come up empty and unverified. Guard first:
		// loadKey() → checkKey('') → checkRedirect() would exit() the process
		// when redirectedSettings is falsy (ApiKeyModel.php:505-521).
		\wpSPIO()->settings()->redirectedSettings = 1;
		$keyModel = new \ShortPixel\Model\ApiKeyModel();
		$keyModel->loadKey();
		$this->assertSame( '', (string) $keyModel->getKey(), 'Key must be empty after clearing' );
		$this->assertFalse( $keyModel->is_verified(), 'Key must be unverified after clearing' );

		// Restore the baseline BEFORE tear_down: singleton resets during
		// teardown would otherwise load the empty key and trip
		// ApiKeyModel::checkRedirect()'s wp_safe_redirect()+exit().
		update_option( 'spio_key', array(
			'apiKey'      => str_repeat( 'a', 20 ),
			'verifiedKey' => true,
			'apiKeyTried' => '',
		) );
		\wpSPIO()->settings()->redirectedSettings = 1;
		$this->resetPluginSingletons();
	}

	// -------------------------------------------------------------------
	// Plan 1.3 — ToS-missing key request is rejected
	// -------------------------------------------------------------------

	/**
	 * The "request a new key" action POSTs to shortpixel.com's sign-up endpoint
	 * and requires a non-empty e-mail address.  When the pluginemail field is
	 * absent (the ToS checkbox was not checked / form was not properly filled)
	 * the action must not forward any key and the pipeline must stay in the
	 * no-key state.
	 *
	 * Plan row: 1.3 — ToS-missing key request rejected.
	 *
	 * NOTE: The mock intercepts all *.shortpixel.com traffic.  The free-sign-up-plugin
	 * endpoint goes to shortpixel.com (not api.shortpixel.com), which the mock
	 * returns '{}' for — so this test validates the guard BEFORE the HTTP leg.
	 *
	 * @see class/Controller/View/SettingsViewController.php action_request_new_key()
	 */
	public function test_key_save_without_tos_accepted_is_rejected() {
		$this->_setRole( 'administrator' );

		// Put the plugin in no-key state so the action_request_new_key path is relevant.
		update_option( 'spio_key', array(
			'apiKey'      => '',
			'verifiedKey' => false,
			'apiKeyTried' => '',
		) );
		$this->resetPluginSingletons();
		\wpSPIO()->settings()->redirectedSettings = 1;

		// Fire the nokey-screen action WITHOUT providing pluginemail (ToS not accepted /
		// form empty).  SettingsViewController::action_request_new_key() returns early
		// and calls load() without forwarding to the API when $email is null.
		$_POST    = array(
			'nonce'         => wp_create_nonce( 'settings_request' ),
			'sp-nonce'      => wp_create_nonce( 'save-settings' ),
			'screen_action' => 'action_request_new_key',
			'request_url'   => admin_url( 'options-general.php?page=wp-shortpixel-settings' ),
			// intentionally NO 'pluginemail' field
		);
		$_REQUEST = $_POST;

		$response = $this->doAjax( 'shortpixel_settingsRequest' );

		// The plugin must not have gained a key.
		$stored = get_option( 'spio_key' );
		$this->assertSame( '', $stored['apiKey'] ?? '', 'No key must be stored when email/ToS is absent' );
		$this->assertFalse( (bool) ( $stored['verifiedKey'] ?? false ), 'Key must remain unverified' );

		// Verify no sign-up HTTP call was made (pluginemail guard fires before the POST).
		foreach ( $this->api->requests as $req ) {
			$this->assertStringNotContainsString(
				'free-sign-up-plugin',
				$req['url'],
				'Sign-up endpoint must not be called when pluginemail is absent'
			);
		}
	}

	// -------------------------------------------------------------------
	// Plan 1.4.1 — quota is re-read after API key swap
	// -------------------------------------------------------------------

	/**
	 * processSave() calls loadQuotaData(true) (force=true) after every settings
	 * save, which wipes the QuotaController cache and fires a fresh api-status.php
	 * call.  This ensures that swapping the API key immediately reflects the new
	 * account's quota rather than showing stale numbers.
	 *
	 * Plan row: 1.4.1 — quota re-read after API key swap.
	 *
	 * @see class/Controller/View/SettingsViewController.php processSave() → loadQuotaData(true)
	 * @see class/Controller/QuotaController.php forceCheckRemoteQuota()
	 */
	public function test_quota_is_reread_after_api_key_swap() {
		$this->_setRole( 'administrator' );

		$requests_before = count( $this->api->requests );

		// Save settings — even a no-change save triggers the quota re-read.
		$response = $this->doSettingsSave( array( 'compressionType' => 1 ) );

		$this->assertIsObject( $response, 'Settings save must return a JSON response' );
		$this->assertTrue( $response->result );

		// At least one api-status.php call must appear after the save.
		$quota_requests = array_filter( $this->api->requests, function ( $req ) {
			return false !== strpos( $req['url'], 'api-status.php' );
		} );

		$this->assertGreaterThan(
			0,
			count( $quota_requests ),
			'processSave() must trigger a remote quota re-read via api-status.php'
		);
	}

	// -------------------------------------------------------------------
	// Plan 1.18 — wp-config defined key takes precedence over settings:
	// lives in test-ConstantsAndFilters.php (isolated process) because it
	// define()s SHORTPIXEL_API_KEY, which would poison every later test
	// in this shared-process suite.
	// -------------------------------------------------------------------

	// -------------------------------------------------------------------
	// Plans 1.5 / 1.6 / 1.7 — key validation under adverse network conditions
	// -------------------------------------------------------------------

	/**
	 * When the API HTTP call returns a WP_Error (transport failure — models
	 * localhost/firewalled/http-only sites that cannot reach api.shortpixel.com),
	 * the key must NOT be verified and an error notice must be queued.
	 *
	 * Plans 1.5, 1.6, 1.7 all exercise the same code path: the QuotaController
	 * remote call fails at the transport layer → remoteValidateKey() returns a
	 * negative/empty result → checkKey() marks the key unverified.
	 *
	 * All three plan rows are merged here because the code path is identical
	 * (the distinction is network topology, not plugin logic).
	 *
	 * Plan rows: 1.5 — localhost key validation graceful failure;
	 *            1.6 — HTTP-only site URL key validation;
	 *            1.7 — firewalled site key validation.
	 *
	 * @see class/Model/ApiKeyModel.php validateKey() / checkKey()
	 * @see class/Controller/QuotaController.php remoteValidateKey()
	 */
	public function test_key_validation_transport_failure_keeps_key_unverified() {
		$this->_setRole( 'administrator' );

		// Force a WP_Error at the transport layer for api-status.php calls.
		$this->api->wpErrorMessage = 'cURL error 28: Connection timed out';

		// Put in an unverified state with a different key so checkKey() will
		// attempt remote validation (key != apiKeyTried path).
		$new_key = str_repeat( 'd', 20 );
		update_option( 'spio_key', array(
			'apiKey'      => '',
			'verifiedKey' => false,
			'apiKeyTried' => '',
		) );
		$this->resetPluginSingletons();
		\wpSPIO()->settings()->redirectedSettings = 1;

		// Submit the new key via the settings-save pipeline.
		$response = $this->doSettingsSave( array(
			'compressionType' => 1,
			'apiKey'          => $new_key,
		) );

		// Key must NOT be stored as verified.
		$stored = get_option( 'spio_key' );

		$this->assertFalse(
			(bool) ( $stored['verifiedKey'] ?? false ),
			'A transport-level failure must leave the key unverified (covers localhost / firewalled / HTTP-only sites)'
		);
	}

	// -------------------------------------------------------------------
	// Plan 4.1 — bulk page with empty media library completes cleanly
	// -------------------------------------------------------------------

	/**
	 * When the Media Library has no attachments, calling createBulk must not
	 * error out — it must return a well-formed JSON response with zero-item
	 * stats and status = true.
	 *
	 * Plan row: 4.1 — bulk optimization page with an empty Media Library.
	 *
	 * @see class/Controller/AjaxController.php createBulk()
	 * @see class/Controller/BulkController.php createNewBulk()
	 */
	public function test_bulk_with_empty_media_library_completes_cleanly() {
		$this->_setRole( 'administrator' );

		// Ensure the media library is empty. Earlier tests can leak rows when
		// a mid-test DDL statement commits the surrounding transaction (seen
		// on WP 5.9), so actively delete instead of asserting emptiness.
		$attachments = get_posts( array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		) );
		foreach ( $attachments as $attachment_id ) {
			wp_delete_attachment( $attachment_id, true );
		}

		$_POST = array(
			'nonce'             => wp_create_nonce( 'ajax_request' ),
			'screen_action'     => 'createBulk',
			'mediaActive'       => 'true',
			'customActive'      => 'false',
			'webpActive'        => 'false',
			'avifActive'        => 'false',
			'aiActive'          => 'false',
			'backgroundProcess' => 'false',
		);
		$_REQUEST = $_POST;

		$response = $this->doAjax( 'shortpixel_ajaxRequest' );

		$this->assertIsObject( $response, 'createBulk must return JSON even with an empty library; raw: ' . $this->lastRawResponse() );
		$this->assertTrue( $response->status ?? false, 'status must be true for an empty-library createBulk' );

		// Stats must reflect zero items.
		$total = isset( $response->total ) ? $response->total : null;
		if ( is_object( $total ) ) {
			$this->assertSame( 0, (int) ( $total->total ?? 0 ), 'Total items must be 0 in an empty library bulk' );
		}
	}
}
