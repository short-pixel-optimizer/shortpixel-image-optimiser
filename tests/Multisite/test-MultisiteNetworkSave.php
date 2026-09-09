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
 * Also regression-tests bug #41 (FIXED in 8520324e): settingsFormSubmit()
 * now runs checkActionAccess($action, 'is_super_admin') before entering
 * the MultiSiteViewController branch, so a client-posted is_network_admin
 * flag alone no longer lets a regular subsite administrator (who lacks
 * manage_network) write network-wide settings — the request is refused
 * with NO_ACCESS before any save runs.
 *
 * @package Shortpixel_Image_Optimiser
 */

use ShortPixel\Controller\AjaxController;

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
	 * Regression for bug #41 (FIXED in 8520324e): a regular subsite
	 * administrator who is NOT a super admin must be refused the network
	 * save. settingsFormSubmit() now calls checkActionAccess($action,
	 * 'is_super_admin') — 'manage_network' on multisite — before
	 * instantiating MultiSiteViewController, exactly the fix this pin's
	 * flip instructions asked for.
	 */
	public function test_pin41_flipped_regular_admin_is_refused_network_save_regression_41() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$this->assertFalse( is_super_admin( $user_id ), 'Sentinel: this user must NOT be a super admin' );
		$this->assertTrue( current_user_can( 'manage_options' ), 'Sentinel: subsite admin has manage_options' );

		$response = $this->doNetworkSettingsSave(
			array( 'network_settings_override_enabled' => 'on' )
		);

		$this->assertIsObject( $response, 'raw: ' . $this->lastRawResponse() );
		$this->assertObjectHasProperty(
			'error',
			$response,
			'Regression #41: the network save must be refused for non-super admins since 8520324e.'
		);
		$this->assertSame(
			AjaxController::NO_ACCESS,
			$response->error,
			'Regression #41: refusal must be the NO_ACCESS error from checkActionAccess.'
		);

		// Sentinel: the refused request must not have written anything
		// network-wide.
		$this->assertFalse(
			get_site_option( 'spio_wpmu', false ),
			'Regression #41: the spio_wpmu network option must remain untouched after a refused save.'
		);
	}

	/**
	 * Regression for bug #41's WIDENED VECTOR (FIXED in 8520324e): the
	 * routing flag $_POST['is_network_admin'] is still client-supplied, but
	 * posting it no longer selects an unguarded MultiSiteViewController —
	 * the is_super_admin capability check runs first, so the flag alone
	 * (from any context the user can produce a valid nonce for) yields
	 * NO_ACCESS for non-super admins. Kept separate from the main
	 * regression test to keep guarding the specific forged-flag POST shape.
	 */
	public function test_pin41_flipped_client_flag_alone_no_longer_reaches_network_save_regression_41() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$this->assertFalse( is_super_admin( $user_id ), 'Sentinel: plain subsite admin' );

		// Post-shape indistinguishable from a legit network save: the
		// is_network_admin flag is the ONLY routing input server-side.
		$response = $this->doNetworkSettingsSave(
			array( 'network_settings_override_enabled' => 'on' )
		);

		$this->assertIsObject( $response, 'raw: ' . $this->lastRawResponse() );
		$this->assertObjectHasProperty(
			'error',
			$response,
			'Regression #41 (widened vector): a forged is_network_admin flag must be refused since 8520324e.'
		);
		$this->assertSame( AjaxController::NO_ACCESS, $response->error );
		$this->assertFalse(
			get_site_option( 'spio_wpmu', false ),
			'Regression #41 (widened vector): no network-wide option may be written on a refused save.'
		);
	}
}
