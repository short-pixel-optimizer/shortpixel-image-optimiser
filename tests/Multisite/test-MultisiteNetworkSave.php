<?php
/**
 * Network settings save via the real admin-ajax dispatch.
 *
 * Run under a multisite WordPress test install (WP_MULTISITE=1 — see
 * bin/test.sh --ms); every test self-skips on single-site.
 *
 * Complements the reflection-level processSave() coverage in
 * test-Multisite.php: here the 'save-multi-settings' screen_action travels
 * the full wp_ajax_shortpixel_settingsRequest route
 * (AjaxController::settingsRequest → checkNonce('settings_request') →
 * checkActionAccess($action, 'is_admin_user') → settingsFormSubmit →
 * MultiSiteViewController → wp_send_json).
 *
 * Also pins bug #41: the network save is gated only by 'is_admin_user'
 * (manage_options), so a regular subsite administrator — NOT a super
 * admin — can write network-wide settings through admin-ajax. The
 * manage_network_options capability only protects the network settings
 * menu page render, not this save endpoint.
 *
 * @package Shortpixel_Image_Optimiser
 */

class MultisiteNetworkSaveTest extends SPIO_AjaxTestCase {

	public function set_up() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite suite needs a multisite test install — run via bin/test.sh --ms (WP_MULTISITE=1).' );
		}
		parent::set_up();
		delete_site_option( 'spio_wpmu' );
	}

	public function tear_down() {
		delete_site_option( 'spio_wpmu' );
		parent::tear_down();
	}

	/**
	 * POST a 'save-multi-settings' request through the real ajax route.
	 * Nonces are created AFTER the caller sets the current user (they are
	 * user-bound).
	 *
	 * @param array $fields Extra settings fields to post.
	 * @return object|null Decoded JSON response.
	 */
	private function doNetworkSettingsSave( array $fields ): ?object {
		$_POST = array_merge(
			array(
				'nonce'         => wp_create_nonce( 'settings_request' ),
				'sp-nonce'      => wp_create_nonce( 'save-multi-settings' ),
				'screen_action' => 'save-multi-settings',
				'request_url'   => network_admin_url( 'settings.php?page=shortpixel-network-settings' ),
			),
			$fields
		);
		$_REQUEST = $_POST;

		return $this->doAjax( 'shortpixel_settingsRequest' );
	}

	public function test_super_admin_network_save_round_trip_succeeds() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		grant_super_admin( $user_id );
		wp_set_current_user( $user_id );

		$response = $this->doNetworkSettingsSave(
			array(
				'network_settings_override_enabled' => 'on',
				'createWebp'                        => 'on',
				'compressionType'                   => '2',
			)
		);

		$this->assertIsObject( $response, 'Network ajax save must terminate through wp_send_json — raw: ' . $this->lastRawResponse() );
		$this->assertTrue( $response->result, 'Super admin save-multi-settings must succeed' );
	}

	/**
	 * BUG #41 (pinned): a regular subsite administrator who is NOT a super
	 * admin can save NETWORK-WIDE settings. settingsRequest() gates
	 * 'save-multi-settings' with checkActionAccess($action, 'is_admin_user')
	 * = manage_options only; nothing on the ajax path requires
	 * manage_network_options.
	 *
	 * When Bas fixes this, the save must be refused for non-super admins:
	 * flip the assertions to expect AjaxController::NO_ACCESS and remove
	 * this pin note.
	 */
	public function test_pin41_regular_admin_can_save_network_settings() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$this->assertFalse( is_super_admin( $user_id ), 'Sentinel: this user must NOT be a super admin' );
		$this->assertTrue( current_user_can( 'manage_options' ), 'Sentinel: subsite admin has manage_options' );

		$response = $this->doNetworkSettingsSave(
			array( 'network_settings_override_enabled' => 'on' )
		);

		$this->assertIsObject( $response, 'raw: ' . $this->lastRawResponse() );
		$this->assertObjectNotHasProperty(
			'error',
			$response,
			'BUG #41 pin: expected the current (buggy) success. If this now reports an error, the network-save gate was fixed — flip this test to assert AjaxController::NO_ACCESS.'
		);
		$this->assertTrue(
			$response->result,
			'BUG #41 pin: a regular subsite admin can currently write network-wide settings via admin-ajax. If this fails, the bug is fixed — flip the test.'
		);
	}
}
