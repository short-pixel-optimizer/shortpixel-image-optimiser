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
	 * @param string $screen_action The screen_action to route. Default form_submit.
	 * @return object|null Decoded JSON response.
	 */
	private function doSettingsSave( array $fields, string $screen_action = 'form_submit' ): ?object {
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
		$_POST['screen_action'] = 'form_submit';

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
	 * PINNED (bug, found 2026-07-18): processWebP() line ~1325 uses
	 * ASSIGNMENT in `elseif($altering = 'deliverWebpAlteredGlobal')` instead
	 * of comparison. The branch is therefore always truthy, so ANY altering
	 * value other than 'deliverWebpAlteredWP' — including garbage or a
	 * missing field — silently enables mode 1 (global .htaccess rewrite)
	 * where 0 (disabled) is the only defensible result. One-char fix: `==`.
	 *
	 * This pins the BUGGY behaviour so the suite stays green. When the fix
	 * lands this test FAILS — then flip the expectation to 0.
	 */
	public function test_deliverwebp_unknown_altering_type_enables_global_pinned() {
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
			1,
			\wpSPIO()->settings()->deliverWebp,
			'processWebP() assignment-instead-of-comparison appears FIXED — flip this pinned test to expect 0.'
		);
	}
}
