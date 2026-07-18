<?php
/**
 * Admin-ajax dispatch tests for AjaxController.
 *
 * Unlike the Controllers unit tests (which call AjaxController methods
 * directly), these run the REAL admin-ajax path via WP_Ajax_UnitTestCase:
 * the `wp_ajax_*` hook wiring from ShortPixelPlugin::ajaxHooks(), the
 * nonce gate (checkNonce), the capability gate (checkActionAccess) and
 * the JSON termination through send()/wp_send_json(). This is the layer
 * a security review pokes at, and the one direct method calls skip.
 *
 * Scope: the security/dispatch layer only. The handlers behind the
 * gates are covered by the Controllers and Integration suites.
 *
 * @package Shortpixel_Image_Optimiser
 */

use ShortPixel\Controller\AjaxController;

class AjaxEndpointTest extends WP_Ajax_UnitTestCase {

	public function set_up() {
		parent::set_up();

		// Same healthy-install baseline as SPIO_IntegrationTestCase: a
		// verified key so controller construction never bails or redirects
		// before the request reaches the gates under test.
		update_option(
			'spio_key',
			array(
				'apiKey'      => str_repeat( 'a', 20 ),
				'verifiedKey' => true,
				'apiKeyTried' => '',
			)
		);

		$settings                     = \wpSPIO()->settings();
		$settings->quotaExceeded      = 0;
		$settings->redirectedSettings = 1;

		// Hermeticity: _handleAjax() fires do_action('admin_init') before
		// dispatching, and SPIO's admin_init chain (quota retrieval, remote
		// notices) performs REAL HTTP calls here — a failed/unexpected
		// response can echo a PHP warning (RequestManager::getJsonStrings
		// on a non-API body) into the ajax output buffer, corrupting the
		// JSON nondeterministically. These tests target the wp_ajax_*
		// dispatch layer only, so drop the admin_init chain and block all
		// outbound HTTP.
		remove_all_actions( 'admin_init' );
		add_filter(
			'pre_http_request',
			function () {
				return new WP_Error( 'http_blocked', 'No outbound HTTP in ajax endpoint tests.' );
			}
		);
	}

	/**
	 * Fire an admin-ajax action and return the decoded JSON response.
	 *
	 * send() terminates via wp_send_json() → wp_die(), which the ajax
	 * die-handler converts into an exception after capturing the output
	 * buffer into $this->_last_response.
	 */
	private function doAjax( string $action ): ?object {
		$_POST['action'] = $action;
		try {
			$this->_handleAjax( $action );
		} catch ( WPAjaxDieContinueException $e ) {
			unset( $e );
		} catch ( WPAjaxDieStopException $e ) {
			unset( $e );
		}
		return json_decode( $this->_last_response );
	}

	/**
	 * The endpoint wiring itself: every SPIO ajax action is registered for
	 * logged-in users only — no wp_ajax_nopriv_* variants exist, so
	 * anonymous requests never reach plugin code (WP core 400s them).
	 */
	public function test_endpoints_are_registered_and_admin_only() {
		$actions = array(
			'shortpixel_image_processing',
			'shortpixel_propose_upgrade',
			'shortpixel_check_quota',
			'shortpixel_ajaxRequest',
			'shortpixel_settingsRequest',
		);

		foreach ( $actions as $action ) {
			$this->assertNotFalse(
				has_action( 'wp_ajax_' . $action ),
				"wp_ajax_$action must be registered by ajaxHooks()"
			);
			$this->assertFalse(
				has_action( 'wp_ajax_nopriv_' . $action ),
				"wp_ajax_nopriv_$action must NOT exist — SPIO ajax is admin-only"
			);
		}
	}

	public function test_bad_nonce_is_rejected_with_nonce_failed() {
		$this->_setRole( 'administrator' );

		$_POST['nonce']         = 'definitely-not-a-valid-nonce';
		$_POST['screen_action'] = 'optimizeItem';
		$_POST['id']            = 123;
		$_POST['type']          = 'media';

		$response = $this->doAjax( 'shortpixel_ajaxRequest' );

		$this->assertIsObject( $response );
		$this->assertSame( AjaxController::NONCE_FAILED, $response->error );
		$this->assertFalse( $response->status );
	}

	public function test_process_queue_with_wrong_nonce_action_is_rejected() {
		$this->_setRole( 'administrator' );

		// A REAL nonce, but for the wrong action — 'ajax_request' instead of
		// the 'processing' this endpoint requires. Catches gate mix-ups that
		// a missing/garbage nonce test would not.
		$_POST['nonce'] = wp_create_nonce( 'ajax_request' );

		$response = $this->doAjax( 'shortpixel_image_processing' );

		$this->assertIsObject( $response );
		$this->assertSame( AjaxController::NONCE_FAILED, $response->error );
	}

	public function test_subscriber_with_valid_nonce_is_rejected_with_no_access() {
		$this->_setRole( 'subscriber' );

		$_POST['nonce']         = wp_create_nonce( 'ajax_request' );
		$_POST['screen_action'] = 'optimizeItem';
		$_POST['id']            = 123;
		$_POST['type']          = 'media';

		$response = $this->doAjax( 'shortpixel_ajaxRequest' );

		$this->assertIsObject( $response );
		$this->assertSame( AjaxController::NO_ACCESS, $response->error );
		$this->assertFalse( $response->status );
	}

	/**
	 * An author (the minimum level for ajaxRequest) with a valid nonce gets
	 * PAST both gates: an unknown screen_action reaches the dispatcher's
	 * default branch (NO_ACTION), proving the rejection in the tests above
	 * comes from the gates and not from something later in the chain.
	 */
	public function test_author_with_valid_nonce_reaches_the_dispatcher() {
		$this->_setRole( 'author' );

		$_POST['nonce']         = wp_create_nonce( 'ajax_request' );
		$_POST['screen_action'] = 'not_a_real_action';

		$response = $this->doAjax( 'shortpixel_ajaxRequest' );

		$this->assertIsObject( $response );
		$this->assertSame( AjaxController::NO_ACTION, $response->error );
	}

	public function test_admin_with_valid_nonce_can_run_the_queue_endpoint() {
		$this->_setRole( 'administrator' );

		$_POST['nonce']  = wp_create_nonce( 'processing' );
		$_POST['isBulk'] = 'false';
		$_POST['queues'] = 'media,custom';

		$response = $this->doAjax( 'shortpixel_image_processing' );

		$this->assertIsObject( $response );
		// The queue is empty, so the exact payload is uninteresting — what
		// matters is that no security gate fired.
		$gate_errors = array(
			AjaxController::NONCE_FAILED,
			AjaxController::NO_ACCESS,
			AjaxController::PROCESSOR_ACTIVE,
		);
		$this->assertFalse(
			isset( $response->error ) && in_array( (int) $response->error, $gate_errors, true ),
			'No nonce/capability/processor gate may fire for an authorized admin request'
		);
	}
}
