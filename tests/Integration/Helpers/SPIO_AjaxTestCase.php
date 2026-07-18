<?php
/**
 * Base class for SPIO admin-ajax integration tests.
 *
 * Combines WP_Ajax_UnitTestCase (real wp_ajax_* dispatch, ajax die-handler,
 * output-buffer capture) with the shared SPIO baseline from
 * SPIO_IntegrationHelpers (mock API, verified key, queue/meta hygiene).
 *
 * Adds the hermeticity measures learned in test-AjaxEndpoint.php:
 * _handleAjax() fires do_action('admin_init') before dispatching, and SPIO's
 * admin_init chain (quota retrieval, remote notices) performs REAL HTTP
 * calls — a failed/unexpected response can echo a PHP warning into the ajax
 * output buffer and corrupt the JSON nondeterministically. So the admin_init
 * chain is dropped and any HTTP request the MockShortPixelApi does not
 * handle is blocked outright.
 *
 * @package Shortpixel_Image_Optimiser
 */

abstract class SPIO_AjaxTestCase extends WP_Ajax_UnitTestCase {

	use SPIO_IntegrationHelpers;

	public function set_up() {
		parent::set_up();
		$this->spioSetUpBaseline();

		remove_all_actions( 'admin_init' );

		// Late safety net: the mock (priority 10) serves api.shortpixel.com
		// traffic; anything still unhandled at 9999 gets a hard error instead
		// of leaving the container and polluting the ajax output buffer.
		add_filter(
			'pre_http_request',
			function ( $preempt ) {
				if ( false !== $preempt ) {
					return $preempt;
				}
				return new WP_Error( 'http_blocked', 'No outbound HTTP in ajax integration tests.' );
			},
			9999
		);
	}

	public function tear_down() {
		$this->spioTearDownBaseline();
		parent::tear_down();
	}

	/**
	 * Fire an admin-ajax action and return the decoded JSON response.
	 *
	 * send() / wp_send_json() terminate via wp_die(), which the ajax
	 * die-handler converts into an exception after capturing the output
	 * buffer into $this->_last_response.
	 *
	 * @param string $action The wp_ajax_* action name (also set as $_POST['action']).
	 * @return object|null Decoded JSON response, or null when the buffer held no valid JSON.
	 */
	protected function doAjax( string $action ): ?object {
		$this->_last_response = '';
		$_POST['action']      = $action;

		// Some SPIO paths (e.g. ErrorController fatal capture in the settings
		// save flow) open an output buffer that the wp_die() exception skips
		// closing. PHPUnit flags the leftover level as risky — unwind to the
		// level we started at.
		$ob_level = ob_get_level();
		try {
			$this->_handleAjax( $action );
		} catch ( WPAjaxDieContinueException $e ) {
			unset( $e );
		} catch ( WPAjaxDieStopException $e ) {
			unset( $e );
		}
		while ( ob_get_level() > $ob_level ) {
			ob_end_clean();
		}
		return json_decode( $this->_last_response );
	}

	/** The raw (undecoded) output of the last doAjax() call. */
	protected function lastRawResponse(): string {
		return (string) $this->_last_response;
	}
}
