<?php
/**
 * Tests for ShortPixel\Controller\Api\AiController.
 *
 * Scope: AI-specific status constants, handleResponse() for the 'requestAlt'
 * action path (id present, overquota, invalid-URL, error, no-id), handleResponse()
 * for the 'retrieveAlt' action path (status -1/0/1/2), handleSuccess() merging
 * aiData with returndatalist backfill, returnFailure() override (401 JWT clear),
 * doRequest() endpoint selection (add-url vs get-url), and JWT transient caching.
 *
 * Out of scope / why:
 * - processMediaItem(): assembles headers, builds body, then calls doRequest()
 *   which fires wp_remote_post() — skipped to avoid live network calls.
 * - getInstance() singleton contract: covered by the base RequestManager tests.
 * - Real JWT renewal round-trips: would require a live API; JWT clearing on 401
 *   is covered here without network.
 *
 * @package Shortpixel_Image_Optimiser
 */

use ShortPixel\Controller\Api\AiController;
use ShortPixel\Controller\Api\RequestManager;
use ShortPixel\Model\Queue\QueueItem;

class AiControllerTest extends WP_UnitTestCase {

	/** @var AiController */
	private $ai;

	public function set_up() {
		parent::set_up();
		$this->resetSingleton();
		$this->ai = new AiController();
		// Ensure no stale JWT transient bleeds between tests.
		delete_transient( 'spio_ai_jwt_token' );
	}

	public function tear_down() {
		$this->resetSingleton();
		delete_transient( 'spio_ai_jwt_token' );
		parent::tear_down();
	}

	private function resetSingleton(): void {
		$ref = new ReflectionClass( AiController::class );
		$p   = $ref->getParentClass()->getProperty( 'instances' );
		$p->setAccessible( true );
		$instances = $p->getValue( null ) ?? [];
		unset( $instances[ AiController::class ] );
		$p->setValue( null, $instances );
	}

	// -----------------------------------------------------------------------
	// Reflection helpers
	// -----------------------------------------------------------------------

	private function invokeProtected( string $method, array $args = [] ) {
		$ref = new ReflectionClass( AiController::class );
		$m   = $ref->getMethod( $method );
		$m->setAccessible( true );
		return $m->invoke( $this->ai, ...$args );
	}

	private function getPrivate( string $property ) {
		$ref = new ReflectionClass( AiController::class );
		// Property may be defined on this class or an ancestor.
		while ( $ref ) {
			if ( $ref->hasProperty( $property ) ) {
				$p = $ref->getProperty( $property );
				$p->setAccessible( true );
				return $p->getValue( $this->ai );
			}
			$ref = $ref->getParentClass();
		}
		throw new \InvalidArgumentException( "Property $property not found." );
	}

	/**
	 * Build a QueueItem configured for AI operations.
	 */
	private function makeAiQueueItem( string $action, array $extraData = [] ): QueueItem {
		$qItem = new QueueItem( [ 'item_id' => 42 ] );
		$qItem->data()->action = $action;
		foreach ( $extraData as $key => $val ) {
			$qItem->data()->$key = $val;
		}
		return $qItem;
	}

	/**
	 * Build a fake raw wp_remote_post() response carrying a JSON body.
	 */
	private function makeRawResponse( array $bodyData ): array {
		return [
			'response' => [ 'code' => 200, 'message' => 'OK' ],
			'body'     => json_encode( $bodyData ),
		];
	}

	// -----------------------------------------------------------------------
	// AI-specific status constants
	// -----------------------------------------------------------------------

	public function test_ai_status_invalid_url_has_value_2() {
		$this->assertSame( 2, AiController::AI_STATUS_INVALID_URL );
	}

	public function test_ai_status_overquota_has_value_3() {
		$this->assertSame( 3, AiController::AI_STATUS_OVERQUOTA );
	}

	// -----------------------------------------------------------------------
	// handleResponse — requestAlt: id present
	// -----------------------------------------------------------------------

	public function test_handleResponse_requestAlt_with_id_returns_success_and_stores_remote_id() {
		$qItem    = $this->makeAiQueueItem( 'requestAlt' );
		$response = $this->makeRawResponse( [ 'id' => 12345 ] );

		$result = $this->invokeProtected( 'handleResponse', [ $qItem, $response ] );

		$this->assertSame( RequestManager::STATUS_SUCCESS, $result['apiStatus'] );
		$this->assertFalse( $result['is_error'] );
		$this->assertTrue( $result['is_done'] );
		$this->assertSame( 12345, $result['remote_id'] );
		// remote_id must also be stored on the item result.
		$this->assertSame( 12345, $qItem->result()->remote_id );
	}

	// -----------------------------------------------------------------------
	// handleResponse — requestAlt: overquota
	// -----------------------------------------------------------------------

	/**
	 * A status-only response (no `id`, no `error` key) carrying
	 * AI_STATUS_OVERQUOTA must return STATUS_QUOTA_EXCEEDED, not a
	 * STATUS_WAITING retry — the status checks run before the
	 * "response without result object" guard.
	 */
	public function test_handleResponse_requestAlt_overquota_returns_quota_exceeded() {
		$qItem    = $this->makeAiQueueItem( 'requestAlt' );
		$response = $this->makeRawResponse( [ 'status' => AiController::AI_STATUS_OVERQUOTA ] );

		$result = $this->invokeProtected( 'handleResponse', [ $qItem, $response ] );

		$this->assertSame( RequestManager::STATUS_QUOTA_EXCEEDED, $result['apiStatus'] );
		$this->assertTrue( $result['is_error'] );
		$this->assertTrue( $result['is_done'] );
	}

	// -----------------------------------------------------------------------
	// handleResponse — requestAlt: invalid URL
	// -----------------------------------------------------------------------

	/**
	 * A status-only response (no `id`, no `error` key) carrying
	 * AI_STATUS_INVALID_URL must return STATUS_FAIL, not a STATUS_WAITING
	 * retry — the status checks run before the "response without result
	 * object" guard.
	 */
	public function test_handleResponse_requestAlt_invalid_url_returns_status_fail() {
		$qItem    = $this->makeAiQueueItem( 'requestAlt' );
		$response = $this->makeRawResponse( [ 'status' => AiController::AI_STATUS_INVALID_URL ] );

		$result = $this->invokeProtected( 'handleResponse', [ $qItem, $response ] );

		$this->assertSame( RequestManager::STATUS_FAIL, $result['apiStatus'] );
		$this->assertTrue( $result['is_error'] );
	}

	// -----------------------------------------------------------------------
	// handleResponse — requestAlt: generic error
	// -----------------------------------------------------------------------

	public function test_handleResponse_requestAlt_generic_error_returns_status_error() {
		$qItem    = $this->makeAiQueueItem( 'requestAlt' );
		// No id, no recognised status → falls through to generic error.
		$response = $this->makeRawResponse( [ 'error' => 'Something went wrong', 'status' => 99 ] );

		$result = $this->invokeProtected( 'handleResponse', [ $qItem, $response ] );

		$this->assertSame( RequestManager::STATUS_ERROR, $result['apiStatus'] );
		$this->assertTrue( $result['is_error'] );
	}

	// -----------------------------------------------------------------------
	// handleResponse — requestAlt: no id and no error (retry)
	// -----------------------------------------------------------------------

	public function test_handleResponse_requestAlt_no_id_no_error_returns_waiting_retry() {
		$qItem    = $this->makeAiQueueItem( 'requestAlt' );
		// id absent, is_error false (no 'error' key) → returnRetry(STATUS_WAITING)
		$response = $this->makeRawResponse( [] );

		$result = $this->invokeProtected( 'handleResponse', [ $qItem, $response ] );

		$this->assertSame( RequestManager::STATUS_WAITING, $result['apiStatus'] );
		$this->assertFalse( $result['is_done'] );
	}

	// -----------------------------------------------------------------------
	// handleResponse — retrieveAlt: status -1 (error)
	// -----------------------------------------------------------------------

	public function test_handleResponse_retrieveAlt_status_minus1_returns_fail() {
		$qItem    = $this->makeAiQueueItem( 'retrieveAlt' );
		$response = $this->makeRawResponse( [ 'status' => -1, 'error' => 'Processing failed' ] );

		$result = $this->invokeProtected( 'handleResponse', [ $qItem, $response ] );

		$this->assertSame( RequestManager::STATUS_FAIL, $result['apiStatus'] );
		$this->assertTrue( $result['is_error'] );
		$this->assertTrue( $result['is_done'] );
	}

	// -----------------------------------------------------------------------
	// handleResponse — retrieveAlt: status 1 (still processing)
	// -----------------------------------------------------------------------

	public function test_handleResponse_retrieveAlt_status_1_returns_waiting() {
		$qItem    = $this->makeAiQueueItem( 'retrieveAlt' );
		$response = $this->makeRawResponse( [ 'status' => 1 ] );

		$result = $this->invokeProtected( 'handleResponse', [ $qItem, $response ] );

		$this->assertSame( RequestManager::STATUS_WAITING, $result['apiStatus'] );
		$this->assertFalse( $result['is_error'] );
		$this->assertFalse( $result['is_done'] );
	}

	// -----------------------------------------------------------------------
	// handleResponse — retrieveAlt: status 0 (queued, no error) falls through to waiting
	// -----------------------------------------------------------------------

	public function test_handleResponse_retrieveAlt_status_0_no_error_falls_through_to_waiting() {
		$qItem    = $this->makeAiQueueItem( 'retrieveAlt' );
		// status 0 with no 'error' key → $is_error = false → falls through to case '1' → STATUS_WAITING.
		$response = $this->makeRawResponse( [ 'status' => 0 ] );

		$result = $this->invokeProtected( 'handleResponse', [ $qItem, $response ] );

		$this->assertSame( RequestManager::STATUS_WAITING, $result['apiStatus'] );
	}

	// -----------------------------------------------------------------------
	// handleResponse — retrieveAlt: status 2 (success with aiData)
	// -----------------------------------------------------------------------

	public function test_handleResponse_retrieveAlt_status_2_returns_success_with_ai_data() {
		$qItem    = $this->makeAiQueueItem( 'retrieveAlt' );
		$response = $this->makeRawResponse( [
			'status'               => 2,
			'alt'                  => 'A beautiful sunset over the ocean',
			'caption'              => 'Sunset caption',
			'image_description'   => 'The sky turns orange',
			'title'                => 'Sunset Photo',
			'relevance'            => 'high',
			'generated_file_name'  => 'sunset-photo.jpg',
		] );

		$result = $this->invokeProtected( 'handleResponse', [ $qItem, $response ] );

		$this->assertSame( RequestManager::STATUS_SUCCESS, $result['apiStatus'] );
		$this->assertFalse( $result['is_error'] );
		$this->assertTrue( $result['is_done'] );
		$this->assertArrayHasKey( 'aiData', $result );
		$this->assertSame( 'A beautiful sunset over the ocean', $result['aiData']['alt'] );
		$this->assertSame( 'Sunset caption', $result['aiData']['caption'] );
		$this->assertSame( 'The sky turns orange', $result['aiData']['description'] );
		$this->assertSame( 'Sunset Photo', $result['aiData']['post_title'] );
		$this->assertSame( 'sunset-photo.jpg', $result['aiData']['filebase'] );
	}

	// -----------------------------------------------------------------------
	// handleResponse — JWT transient caching
	// -----------------------------------------------------------------------

	public function test_handleResponse_caches_jwt_token_in_transient() {
		$qItem    = $this->makeAiQueueItem( 'requestAlt' );
		$response = $this->makeRawResponse( [ 'id' => 7, 'jwt' => 'abc.def.ghi' ] );

		$this->invokeProtected( 'handleResponse', [ $qItem, $response ] );

		$this->assertSame( 'abc.def.ghi', get_transient( 'spio_ai_jwt_token' ) );
	}

	public function test_handleResponse_does_not_update_transient_when_jwt_unchanged() {
		set_transient( 'spio_ai_jwt_token', 'existing.token.value', HOUR_IN_SECONDS );

		$qItem    = $this->makeAiQueueItem( 'requestAlt' );
		$response = $this->makeRawResponse( [ 'id' => 8, 'jwt' => 'existing.token.value' ] );

		$this->invokeProtected( 'handleResponse', [ $qItem, $response ] );

		// Token is the same; transient must still hold the same value.
		$this->assertSame( 'existing.token.value', get_transient( 'spio_ai_jwt_token' ) );
	}

	// -----------------------------------------------------------------------
	// returnFailure override — 401 clears JWT and returns retry
	// -----------------------------------------------------------------------

	public function test_returnFailure_401_with_stored_token_clears_transient_and_returns_retry() {
		set_transient( 'spio_ai_jwt_token', 'stale.jwt.token', HOUR_IN_SECONDS );

		$result = $this->invokeProtected( 'returnFailure', [ 401, 'Unauthorized' ] );

		// Token must be cleared.
		$this->assertFalse( get_transient( 'spio_ai_jwt_token' ) );
		// Must be a retry (is_done = false), not a hard failure.
		$this->assertFalse( $result['is_done'] );
		$this->assertTrue( $result['is_error'] );
		$this->assertSame( 401, $result['apiStatus'] );
	}

	public function test_returnFailure_401_without_stored_token_falls_through_to_parent() {
		// No transient set → no token to clear → falls through to parent returnFailure.
		$result = $this->invokeProtected( 'returnFailure', [ 401, 'Unauthorized' ] );

		// Parent returnFailure: is_done = true.
		$this->assertTrue( $result['is_done'] );
		$this->assertTrue( $result['is_error'] );
	}

	public function test_returnFailure_non_401_always_falls_through_to_parent() {
		set_transient( 'spio_ai_jwt_token', 'valid.token', HOUR_IN_SECONDS );

		$result = $this->invokeProtected( 'returnFailure', [ 403, 'Forbidden' ] );

		// Token must NOT be cleared for non-401 errors.
		$this->assertSame( 'valid.token', get_transient( 'spio_ai_jwt_token' ) );
		// Parent returnFailure: is_done = true.
		$this->assertTrue( $result['is_done'] );
	}

	// -----------------------------------------------------------------------
	// doRequest — endpoint selection
	// -----------------------------------------------------------------------

	public function test_doRequest_without_remote_id_sets_add_url_endpoint() {
		$qItem = $this->makeAiQueueItem( 'requestAlt' );
		// data()->remote_id is not set (null by default in QueueItemData).
		$qItem->data()->remote_id = null;

		// Intercept wp_remote_post() via the pre_http_request filter so we never
		// make a real network call, but still let doRequest() run endpoint logic.
		$capturedUrl = null;
		add_filter( 'pre_http_request', function ( $preempt, $args, $url ) use ( &$capturedUrl ) {
			$capturedUrl = $url;
			// Return a valid HTTP 200 response body so the rest of doRequest won't error.
			return [
				'headers'  => [],
				'body'     => json_encode( [] ),
				'response' => [ 'code' => 200, 'message' => 'OK' ],
				'cookies'  => [],
			];
		}, 10, 3 );

		// Production request arrays are always built by getRequest(), which
		// always sets 'body' — mirror that so the fixture matches reality.
		$this->invokeProtected( 'doRequest', [ $qItem, [ 'blocking' => false, 'body' => '' ] ] );

		remove_all_filters( 'pre_http_request' );

		$this->assertNotNull( $capturedUrl, 'pre_http_request was not fired — wp_remote_post was not called.' );
		$this->assertStringContainsString( 'add-url', $capturedUrl );
	}

	public function test_doRequest_with_remote_id_sets_get_url_endpoint() {
		$qItem = $this->makeAiQueueItem( 'retrieveAlt' );
		$qItem->data()->remote_id = 999;

		$capturedUrl = null;
		add_filter( 'pre_http_request', function ( $preempt, $args, $url ) use ( &$capturedUrl ) {
			$capturedUrl = $url;
			return [
				'headers'  => [],
				'body'     => json_encode( [] ),
				'response' => [ 'code' => 200, 'message' => 'OK' ],
				'cookies'  => [],
			];
		}, 10, 3 );

		$this->invokeProtected( 'doRequest', [ $qItem, [ 'blocking' => false, 'body' => '' ] ] );

		remove_all_filters( 'pre_http_request' );

		$this->assertStringContainsString( 'get-url', $capturedUrl );
	}

	// -----------------------------------------------------------------------
	// handleSuccess — returndatalist backfill
	// -----------------------------------------------------------------------

	public function test_handleSuccess_backfills_missing_fields_from_returndatalist() {
		$qItem = $this->makeAiQueueItem( 'retrieveAlt' );
		// returndatalist has an 'alt' entry with a stored status but AI returned no 'alt'.
		$qItem->data()->returndatalist = [
			'alt' => [ 'status' => 'skipped' ],
		];

		$aiData = []; // API returned nothing for 'alt'

		$result = $this->invokeProtected( 'handleSuccess', [ $aiData, $qItem ] );

		$this->assertSame( RequestManager::STATUS_SUCCESS, $result['apiStatus'] );
		$this->assertArrayHasKey( 'aiData', $result );
		// The 'alt' field must be backfilled with the stored status.
		$this->assertSame( 'skipped', $result['aiData']['alt'] );
	}

	public function test_handleSuccess_does_not_overwrite_present_fields_from_returndatalist() {
		$qItem = $this->makeAiQueueItem( 'retrieveAlt' );
		$qItem->data()->returndatalist = [
			'alt' => [ 'status' => 'old-value' ],
		];

		$aiData = [ 'alt' => 'Fresh AI alt text' ];

		$result = $this->invokeProtected( 'handleSuccess', [ $aiData, $qItem ] );

		// AI value must not be overwritten by the backfill.
		$this->assertSame( 'Fresh AI alt text', $result['aiData']['alt'] );
	}

	public function test_handleSuccess_with_null_returndatalist_returns_success_unchanged() {
		$qItem = $this->makeAiQueueItem( 'retrieveAlt' );
		$qItem->data()->returndatalist = null;

		$aiData = [ 'caption' => 'A nice photo' ];

		$result = $this->invokeProtected( 'handleSuccess', [ $aiData, $qItem ] );

		$this->assertSame( RequestManager::STATUS_SUCCESS, $result['apiStatus'] );
		$this->assertSame( 'A nice photo', $result['aiData']['caption'] );
	}

	// -----------------------------------------------------------------------
	// handleSuccess — object returndatalist (JSON-decoded from record)
	// -----------------------------------------------------------------------

	public function test_handleSuccess_handles_object_returndatalist() {
		$qItem = $this->makeAiQueueItem( 'retrieveAlt' );
		// Simulate JSON-decode of a stored record yielding a stdClass.
		$returnData        = new stdClass();
		$returnData->title = (object) [ 'status' => 'pending' ];
		$qItem->data()->returndatalist = $returnData;

		$aiData = []; // API returned nothing for 'title'

		$result = $this->invokeProtected( 'handleSuccess', [ $aiData, $qItem ] );

		$this->assertSame( 'pending', $result['aiData']['title'] );
	}
}
