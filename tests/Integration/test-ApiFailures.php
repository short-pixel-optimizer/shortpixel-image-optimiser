<?php
/**
 * Integration tests: API failure scenarios (Wave 3).
 *
 * The real API signals errors via Status->Code inside an HTTP-200 JSON body
 * (ApiController::handleResponse). Transport failures surface as WP_Error
 * (RequestManager::doRequest). This suite drives the real pipeline against
 * MockShortPixelApi failure modes and asserts the plugin degrades the way
 * it should: fatal codes fail the item without touching the image,
 * retryable codes keep the item alive, and quota exhaustion halts the
 * whole queue.
 *
 * Tick mechanics reminder: the FIRST reducer send is non-blocking
 * (tries == 0 → STATUS_ENQUEUED), so the error only reaches the item on
 * the SECOND tick. runQueueUntilEmpty()/tickQueue() backdate between ticks
 * to skip the 10s retry gate.
 *
 * @package Shortpixel_Image_Optimiser
 */

use ShortPixel\Controller\QueueController;
use ShortPixel\Controller\QuotaController;
use ShortPixel\Controller\AjaxController;
use ShortPixel\Model\Image\ImageModel;

class ApiFailuresTest extends SPIO_IntegrationTestCase {

	private function freshImageModel( int $attachment_id ) {
		return \wpSPIO()->filesystem()->getImage( $attachment_id, 'media', false );
	}

	private function enqueueAttachment( int $attachment_id ): void {
		$imageModel      = \wpSPIO()->filesystem()->getImage( $attachment_id, 'media' );
		$queueController = new QueueController();
		$queueController->addItemToQueue( $imageModel );
	}

	/** Tick the queue a fixed number of times WITHOUT requiring it to empty. */
	private function tickQueue( int $times ): void {
		$queueController = new QueueController();
		for ( $tick = 0; $tick < $times; $tick++ ) {
			$queueController->processQueue( array( 'media' ) );
			$this->backdateQueueItems();
		}
	}

	// -------------------------------------------------------------------
	// Quota exceeded (-403): retryable, halts the whole queue
	// -------------------------------------------------------------------

	public function test_quota_exceeded_sets_flag_and_halts_queue() {
		$this->api->forceStatusCode = MockShortPixelApi::CODE_QUOTA_EXCEEDED;

		$id = $this->uploadFixture( 'fixture-small.jpg' );
		$this->enqueueAttachment( $id );
		$this->tickQueue( 3 );

		$this->assertFalse( $this->freshImageModel( $id )->isOptimized(), 'No optimization may be recorded when the API reports quota exceeded.' );
		$this->assertFalse( QuotaController::getInstance()->hasQuota(), 'Quota-exceeded response must flip the local quota state.' );

		// With the flag set, processQueue refuses to run at all.
		$queueController = new QueueController();
		$result          = $queueController->processQueue( array( 'media' ) );
		$this->assertObjectHasProperty( 'error', $result );
		$this->assertSame( AjaxController::NOQUOTA, $result->error, 'Further queue ticks must be refused with the NOQUOTA error.' );
	}

	// -------------------------------------------------------------------
	// Invalid API key (-401): fatal for the item
	// -------------------------------------------------------------------

	public function test_invalid_api_key_fails_item_without_optimizing() {
		$this->api->forceStatusCode = MockShortPixelApi::CODE_INVALID_KEY;

		$id = $this->uploadFixture( 'fixture-small.jpg' );
		$this->enqueueAttachment( $id );
		$this->runQueueUntilEmpty();

		$image = $this->freshImageModel( $id );
		$this->assertFalse( $image->isOptimized(), 'An invalid-key response must not mark the image optimized.' );
		$this->assertNotSame( ImageModel::FILE_STATUS_SUCCESS, (int) $image->getMeta( 'status' ) );
	}

	// -------------------------------------------------------------------
	// Invalid URL (-102): permanent file error
	// -------------------------------------------------------------------

	public function test_invalid_url_marks_item_as_file_error() {
		$this->api->forceStatusCode = MockShortPixelApi::CODE_INVALID_URL;

		$id = $this->uploadFixture( 'fixture-small.jpg' );
		$this->enqueueAttachment( $id );
		$this->runQueueUntilEmpty();

		$image = $this->freshImageModel( $id );
		$this->assertFalse( $image->isOptimized() );
	}

	// -------------------------------------------------------------------
	// Waiting (Code 1): API still processing, plugin must re-poll
	// -------------------------------------------------------------------

	public function test_waiting_status_retries_until_success() {
		$this->api->waitingRounds = 2;

		$id = $this->uploadFixture( 'fixture-small.jpg' );
		$this->enqueueAttachment( $id );
		$this->runQueueUntilEmpty();

		$this->assertTrue( $this->freshImageModel( $id )->isOptimized(), 'The item must optimize once the API stops answering WAITING.' );
		$this->assertGreaterThanOrEqual( 3, count( $this->api->requests ), 'The plugin must have re-polled through the waiting rounds.' );
	}

	// -------------------------------------------------------------------
	// Malformed JSON body: unrecognized response, graceful failure
	// -------------------------------------------------------------------

	public function test_malformed_response_fails_item_gracefully() {
		$this->api->malformedBody = 'this is not <json>{{';

		$id = $this->uploadFixture( 'fixture-small.jpg' );
		$this->enqueueAttachment( $id );
		$this->runQueueUntilEmpty();

		$image = $this->freshImageModel( $id );
		$this->assertFalse( $image->isOptimized(), 'A garbage API body must not result in an optimized state.' );

		clearstatcache();
		$this->assertSame(
			filesize( $this->fixturePath( 'fixture-small.jpg' ) ),
			filesize( get_attached_file( $id ) ),
			'The original file must be untouched after a malformed response.'
		);
	}

	// -------------------------------------------------------------------
	// Transport timeout (cURL 28): retryable, recovers when API returns
	// -------------------------------------------------------------------

	public function test_connection_timeout_retries_and_recovers() {
		$this->api->wpErrorMessage = 'cURL error 28: Operation timed out after 10001 milliseconds';

		$id = $this->uploadFixture( 'fixture-small.jpg' );
		$this->enqueueAttachment( $id );
		$this->tickQueue( 3 );

		$this->assertFalse( $this->freshImageModel( $id )->isOptimized(), 'The image must not be optimized while the API is unreachable.' );

		// API comes back: the still-queued item must complete on later ticks.
		$this->api->wpErrorMessage = null;
		$this->runQueueUntilEmpty();

		$this->assertTrue( $this->freshImageModel( $id )->isOptimized(), 'The queued item must recover and optimize once the connection works again.' );
	}
}
