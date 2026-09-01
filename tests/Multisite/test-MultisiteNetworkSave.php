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
 * ROUTING NOTE (e4d1d0a8, 2026-08-28): settingsFormSubmit() no longer
 * routes based on $screen_action; it routes based on the client-posted
 * 'is_network_admin' field (added by shortpixel-settings.js when the
 * hidden input in view-settings.php is present). Tests that intend the
 * network save path MUST include is_network_admin=true in POST — without
 * it the request falls into SettingsViewController::load() and then hits
 * the raw exit('ajaxcontroller - formsubmit') left in AjaxController::619,
 * killing the whole PHPUnit run with a false-green.
 *
 * Also pins bug #41: the network save is gated only by 'is_admin_user'
 * (manage_options), so a regular subsite administrator — NOT a super
 * admin — can write network-wide settings through admin-ajax. The
 * manage_network_options capability only protects the network settings
 * menu page render, not this save endpoint. The new is_network_admin
 * routing does NOT close this hole; it widens the attack surface because
 * the field is client-supplied (see the paired widened-vector pin).
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
	 * Includes the client-supplied 'is_network_admin' routing flag introduced
	 * by e4d1d0a8 — without it settingsFormSubmit() would instantiate
	 * SettingsViewController (whose form_action is 'save-settings') and the
	 * sp-nonce 'save-multi-settings' would fail verification, dropping the
	 * request into load() and then into the debug exit at AjaxController:619.
	 *
	 * @param array $fields Extra settings fields to post.
	 * @return object|null Decoded JSON response.
	 */
	private function doNetworkSettingsSave( array $fields ): ?object {
		$_POST = array_merge(
			array(
				'nonce'            => wp_create_nonce( 'settings_request' ),
				'sp-nonce'         => wp_create_nonce( 'save-multi-settings' ),
				'screen_action'    => 'save-multi-settings',
				'is_network_admin' => 'true',
				'request_url'      => network_admin_url( 'settings.php?page=shortpixel-network-settings' ),
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
	 * BUG #41 (pinned_for_deferred_fix): a regular subsite administrator who
	 * is NOT a super admin can save NETWORK-WIDE settings. settingsRequest()
	 * gates 'save-multi-settings' with checkActionAccess($action,
	 * 'is_admin_user') = manage_options only; nothing on the ajax path
	 * requires manage_network_options.
	 *
	 * The e4d1d0a8 routing rework did NOT close this — it only changed the
	 * MECHANISM (was: $screen_action='save-multi-settings' selected the
	 * MultiSiteViewController; now: $_POST['is_network_admin'] selects it).
	 * See test_pin41_widened_vector_* below for the extra attack surface the
	 * client-supplied flag introduced.
	 *
	 * FLIP INSTRUCTIONS: when the bug is fixed (add a super-admin capability
	 * check on the network save path — e.g. checkActionAccess($action,
	 * 'is_super_admin') for is_network_admin requests), the save must be
	 * refused for non-super admins: flip the two assertions to expect
	 * $response->error === AjaxController::NO_ACCESS (or similar) and drop
	 * the _pinned_for_deferred_fix suffix.
	 */
	public function test_pin41_regular_admin_can_save_network_settings_pinned_for_deferred_fix() {
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
			'BUG #41 pin: expected the current (buggy) success. If this now reports an error, the network-save gate was fixed — flip this test to assert AjaxController::NO_ACCESS and drop the _pinned_for_deferred_fix suffix.'
		);
		$this->assertTrue(
			$response->result,
			'BUG #41 pin: a regular subsite admin can currently write network-wide settings via admin-ajax. If this fails, the bug is fixed — flip the test.'
		);

		// Sentinel: the response MUST come from MultiSiteViewController (not
		// SettingsViewController), otherwise a routing change that
		// accidentally down-graded the request to the site path would give a
		// false pass here. The successful notice text is set by processSave()
		// only on the MultiSite path. We assert on the raw response body
		// because the display_notices array is the routing tell.
		$this->assertStringContainsString(
			'Network settings saved',
			$this->lastRawResponse(),
			'BUG #41 pin: the buggy path must actually reach MultiSiteViewController::processSave (its notice is the routing tell). If this fails, the routing/save chain regressed — investigate before adjusting the pin.'
		);
	}

	/**
	 * BUG #41 WIDENED VECTOR (pinned_for_deferred_fix): after e4d1d0a8
	 * settingsFormSubmit() routes to MultiSiteViewController based on the
	 * CLIENT-SUPPLIED $_POST['is_network_admin'] field. The routing gate is
	 * intended to be set by shortpixel-settings.js (via the hidden input in
	 * view-settings.php on the network screen) but nothing server-side
	 * verifies the request actually originated from the network screen.
	 *
	 * So a subsite admin can:
	 *   1. Post to admin-ajax.php from ANY context (site-settings screen,
	 *      cURL, whatever they can produce a valid nonce for);
	 *   2. Set is_network_admin=anything-truthy;
	 *   3. Reach MultiSiteViewController::processSave and mutate the
	 *      network-wide spio_wpmu option.
	 *
	 * This assertion documents the widened attack surface: the routing flag
	 * itself is trusted client input.
	 *
	 * FLIP INSTRUCTIONS: when the routing check is hardened (e.g. gate the
	 * MultiSiteViewController branch on is_super_admin() OR on a
	 * server-derived signal — verified network-screen referer, dedicated
	 * network nonce name, etc.), the save must be refused: flip to
	 * assertObjectHasProperty('error', $response) and drop the
	 * _pinned_for_deferred_fix suffix.
	 */
	public function test_pin41_widened_vector_client_flag_alone_reaches_network_save_pinned_for_deferred_fix() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$this->assertFalse( is_super_admin( $user_id ), 'Sentinel: plain subsite admin' );

		// Post-shape indistinguishable from a legit network save: the
		// is_network_admin flag is the ONLY thing selecting the network
		// controller server-side.
		$response = $this->doNetworkSettingsSave(
			array( 'network_settings_override_enabled' => 'on' )
		);

		$this->assertIsObject( $response, 'raw: ' . $this->lastRawResponse() );
		$this->assertObjectNotHasProperty(
			'error',
			$response,
			'BUG #41 widened vector pin: expected the current (buggy) success. If this now errors, the client-supplied is_network_admin routing flag is being validated server-side — flip this test.'
		);
		$this->assertTrue(
			(bool) $response->result,
			'BUG #41 widened vector pin: a subsite admin can select the MultiSiteViewController save path with a client-supplied POST flag. If this fails, the routing was hardened — flip the test.'
		);
	}
}
