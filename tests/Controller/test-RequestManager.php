<?php
/**
 * Tests for ShortPixel\Controller\Api\RequestManager (abstract).
 *
 * Scope: status constants, returnFailure / returnRetry / returnOK / returnSuccess
 * result-array shapes, parseResponse() with valid JSON, parseResponse() with
 * JSON embedded in surrounding noise (getJsonStrings() path), and a regression
 * test that the undefined-offset warning no longer fires when the body has no JSON
 * (Bug #7 FIXED a81b64d0 + 4b3b4d9f).
 *
 * Out of scope / why:
 * - doRequest(): calls wp_remote_post() and therefore hits the network; excluded
 *   entirely to avoid live API calls — covered by integration tests.
 * - getRequest(): depends on wpSPIO()->settings() returning a real SettingsModel;
 *   exercised indirectly by ApiController tests.
 * - getInstance() singleton: RequestManager is abstract; each concrete subclass
 *   gets its own singleton tested in its own suite.
 * - processMediaItem(): abstract; delegated to concrete-subclass test files.
 *
 * @package Shortpixel_Image_Optimiser
 */

use ShortPixel\Controller\Api\RequestManager;
use ShortPixel\Model\Queue\QueueItem;

/**
 * Minimal concrete subclass that makes the abstract class instantiable for
 * white-box testing of its non-abstract protected methods.
 */
class ConcreteRequestManager extends RequestManager {
	public function handleResponse( QueueItem $qItem, $response ) {
		return $this->returnOK();
	}

	public function processMediaItem( QueueItem $qItem ) {}

	// Expose protected methods publicly for testing.
	public function callParseResponse( array $response ) {
		return $this->parseResponse( $response );
	}

	public function callReturnFailure( $status, $message ) {
		return $this->returnFailure( $status, $message );
	}

	public function callReturnRetry( $status, $message ) {
		return $this->returnRetry( $status, $message );
	}

	public function callReturnOK( $status = self::STATUS_UNCHANGED, $message = false ) {
		return $this->returnOK( $status, $message );
	}

	public function callReturnSuccess( $data, $status = self::STATUS_SUCCESS, $message = false ) {
		return $this->returnSuccess( $data, $status, $message );
	}
}

class RequestManagerTest extends WP_UnitTestCase {

	/** @var ConcreteRequestManager */
	private $rm;

	public function set_up() {
		parent::set_up();
		$this->rm = new ConcreteRequestManager();
	}

	// -----------------------------------------------------------------------
	// Status constants
	// -----------------------------------------------------------------------

	public function test_status_constants_have_expected_integer_values() {
		$this->assertSame( 10,    RequestManager::STATUS_ENQUEUED );
		$this->assertSame( 3,     RequestManager::STATUS_PARTIAL_SUCCESS );
		$this->assertSame( 2,     RequestManager::STATUS_SUCCESS );
		$this->assertSame( 1,     RequestManager::STATUS_WAITING );
		$this->assertSame( 0,     RequestManager::STATUS_UNCHANGED );
		$this->assertSame( -1,    RequestManager::STATUS_ERROR );
		$this->assertSame( -2,    RequestManager::STATUS_FAIL );
		$this->assertSame( -3,    RequestManager::STATUS_QUOTA_EXCEEDED );
		$this->assertSame( -4,    RequestManager::STATUS_SKIP );
		$this->assertSame( -5,    RequestManager::STATUS_NOT_FOUND );
		$this->assertSame( -6,    RequestManager::STATUS_NO_KEY );
		$this->assertSame( -9,    RequestManager::STATUS_OPTIMIZED_BIGGER );
		$this->assertSame( -10,   RequestManager::STATUS_CONVERTED );
		$this->assertSame( -11,   RequestManager::STATUS_NOT_COMPATIBLE );
		$this->assertSame( -404,  RequestManager::STATUS_QUEUE_FULL );
		$this->assertSame( -500,  RequestManager::STATUS_MAINTENANCE );
		$this->assertSame( -503,  RequestManager::STATUS_CONNECTION_ERROR );
		$this->assertSame( -1000, RequestManager::STATUS_NOT_API );
	}

	// -----------------------------------------------------------------------
	// returnFailure
	// -----------------------------------------------------------------------

	public function test_returnFailure_sets_is_error_and_is_done_true() {
		$result = $this->rm->callReturnFailure( RequestManager::STATUS_ERROR, 'bad things happened' );
		$this->assertTrue( $result['is_error'] );
		$this->assertTrue( $result['is_done'] );
	}

	public function test_returnFailure_carries_status_and_message() {
		$result = $this->rm->callReturnFailure( RequestManager::STATUS_FAIL, 'some error message' );
		$this->assertSame( RequestManager::STATUS_FAIL, $result['apiStatus'] );
		$this->assertSame( 'some error message', $result['message'] );
	}

	public function test_returnFailure_accepts_numeric_http_code() {
		$result = $this->rm->callReturnFailure( 404, 'not found' );
		$this->assertSame( 404, $result['apiStatus'] );
		$this->assertTrue( $result['is_done'] );
	}

	// -----------------------------------------------------------------------
	// returnRetry
	// -----------------------------------------------------------------------

	public function test_returnRetry_sets_is_error_true_and_is_done_false() {
		$result = $this->rm->callReturnRetry( RequestManager::STATUS_QUOTA_EXCEEDED, 'quota' );
		$this->assertTrue( $result['is_error'] );
		$this->assertFalse( $result['is_done'] );
	}

	public function test_returnRetry_carries_status_and_message() {
		$result = $this->rm->callReturnRetry( RequestManager::STATUS_QUEUE_FULL, 'queue full' );
		$this->assertSame( RequestManager::STATUS_QUEUE_FULL, $result['apiStatus'] );
		$this->assertSame( 'queue full', $result['message'] );
	}

	// -----------------------------------------------------------------------
	// returnOK
	// -----------------------------------------------------------------------

	public function test_returnOK_sets_is_error_and_is_done_false() {
		$result = $this->rm->callReturnOK();
		$this->assertFalse( $result['is_error'] );
		$this->assertFalse( $result['is_done'] );
	}

	public function test_returnOK_defaults_to_STATUS_UNCHANGED() {
		$result = $this->rm->callReturnOK();
		$this->assertSame( RequestManager::STATUS_UNCHANGED, $result['apiStatus'] );
	}

	public function test_returnOK_accepts_explicit_status_and_message() {
		$result = $this->rm->callReturnOK( RequestManager::STATUS_ENQUEUED, 'queued fine' );
		$this->assertSame( RequestManager::STATUS_ENQUEUED, $result['apiStatus'] );
		$this->assertSame( 'queued fine', $result['message'] );
	}

	// -----------------------------------------------------------------------
	// returnSuccess — STATUS_SUCCESS branch
	// -----------------------------------------------------------------------

	public function test_returnSuccess_success_sets_is_error_false_and_is_done_true() {
		$result = $this->rm->callReturnSuccess( [], RequestManager::STATUS_SUCCESS );
		$this->assertFalse( $result['is_error'] );
		$this->assertTrue( $result['is_done'] );
	}

	public function test_returnSuccess_success_carries_status() {
		$result = $this->rm->callReturnSuccess( [], RequestManager::STATUS_SUCCESS );
		$this->assertSame( RequestManager::STATUS_SUCCESS, $result['apiStatus'] );
	}

	public function test_returnSuccess_merges_data_into_result() {
		$data   = [ 'files' => [ 'a.jpg' ], 'extra' => 42 ];
		$result = $this->rm->callReturnSuccess( $data, RequestManager::STATUS_SUCCESS );
		$this->assertSame( [ 'a.jpg' ], $result['files'] );
		$this->assertSame( 42, $result['extra'] );
	}

	public function test_returnSuccess_with_false_message_omits_message_key() {
		$result = $this->rm->callReturnSuccess( [], RequestManager::STATUS_SUCCESS, false );
		$this->assertArrayNotHasKey( 'message', $result );
	}

	public function test_returnSuccess_with_string_message_keeps_message_key() {
		$result = $this->rm->callReturnSuccess( [], RequestManager::STATUS_SUCCESS, 'done' );
		$this->assertArrayHasKey( 'message', $result );
		$this->assertSame( 'done', $result['message'] );
	}

	// -----------------------------------------------------------------------
	// returnSuccess — STATUS_PARTIAL_SUCCESS branch (is_done absent)
	// -----------------------------------------------------------------------

	public function test_returnSuccess_partial_success_has_no_is_done_key() {
		$result = $this->rm->callReturnSuccess( [], RequestManager::STATUS_PARTIAL_SUCCESS );
		$this->assertArrayNotHasKey( 'is_done', $result );
	}

	public function test_returnSuccess_partial_success_carries_correct_status() {
		$result = $this->rm->callReturnSuccess( [], RequestManager::STATUS_PARTIAL_SUCCESS );
		$this->assertSame( RequestManager::STATUS_PARTIAL_SUCCESS, $result['apiStatus'] );
	}

	// -----------------------------------------------------------------------
	// parseResponse — clean JSON body
	// -----------------------------------------------------------------------

	public function test_parseResponse_decodes_valid_json_body() {
		$payload  = json_encode( [ 'Status' => [ 'Code' => 2, 'Message' => 'ok' ] ] );
		$response = [ 'body' => $payload ];
		$result   = $this->rm->callParseResponse( $response );
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'Status', $result );
	}

	public function test_parseResponse_returns_array_for_json_array_body() {
		$payload  = json_encode( [ [ 'Status' => [ 'Code' => 2 ] ] ] );
		$response = [ 'body' => $payload ];
		$result   = $this->rm->callParseResponse( $response );
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 0, $result );
	}

	public function test_parseResponse_preserves_nested_objects_as_objects() {
		$payload  = json_encode( (object) [ 'Status' => (object) [ 'Code' => 2 ] ] );
		$response = [ 'body' => $payload ];
		$result   = $this->rm->callParseResponse( $response );
		// json_decode without assoc flag makes inner objects stay as stdClass
		// after the outer (array) cast the value at 'Status' remains an object.
		$this->assertIsObject( $result['Status'] );
	}

	// -----------------------------------------------------------------------
	// parseResponse — JSON embedded in surrounding noise (getJsonStrings path)
	// -----------------------------------------------------------------------

	public function test_parseResponse_extracts_json_embedded_in_noise() {
		$json     = json_encode( [ 'Status' => [ 'Code' => -404, 'Message' => 'queue full' ] ] );
		$noisy    = 'Some preamble text... ' . $json . ' ...and some trailing noise.';
		$response = [ 'body' => $noisy ];
		$result   = $this->rm->callParseResponse( $response );
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'Status', $result );
	}

	public function test_parseResponse_extracts_first_json_object_when_multiple_present() {
		$json1    = json_encode( [ 'num' => 1 ] );
		$json2    = json_encode( [ 'num' => 2 ] );
		$noisy    = 'prefix ' . $json1 . ' middle ' . $json2 . ' suffix';
		$response = [ 'body' => $noisy ];
		$result   = $this->rm->callParseResponse( $response );
		// Only the first valid JSON object is decoded.
		$this->assertSame( 1, $result['num'] );
	}

	// -----------------------------------------------------------------------
	// parseResponse — pinned regression: HTML body triggers undefined-offset
	// -----------------------------------------------------------------------

	/**
	 * Bug FIXED (a81b64d0 + 4b3b4d9f): parseResponse() now guards against
	 * getJsonStrings() returning an empty array.  When no JSON object is found in
	 * the body, $data[0] is no longer accessed; instead the method returns an
	 * explicit error array: ['status' => STATUS_ERROR, 'error' => json_last_error_msg()].
	 *
	 * The old "Undefined offset: 0" notice no longer fires, and the return value
	 * is now the sentinel error array rather than an empty array.
	 */
	public function test_parseResponse_plain_html_body_returns_error_sentinel_array() {
		$html     = '<html><body><h1>502 Bad Gateway</h1></body></html>';
		$response = [ 'body' => $html ];

		$warningFired = false;
		$previous     = set_error_handler( function ( $errno ) use ( &$warningFired ) {
			if ( in_array( $errno, [ E_NOTICE, E_WARNING ], true ) ) {
				$warningFired = true;
			}
			return true;
		} );

		$result = $this->rm->callParseResponse( $response );

		restore_error_handler();

		// Bug #7 FIXED (a81b64d0 + 4b3b4d9f): no offset notice/warning is raised.
		$this->assertFalse( $warningFired, 'No undefined-offset notice/warning should be raised after the fix.' );
		// The fixed code returns a status=STATUS_ERROR sentinel.
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'status', $result );
		$this->assertSame( RequestManager::STATUS_ERROR, $result['status'] );
		$this->assertArrayHasKey( 'error', $result );
	}
}
