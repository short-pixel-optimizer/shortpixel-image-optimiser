<?php
/**
 * Integration tests: API failure scenarios.
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

	// -------------------------------------------------------------------
	// Bulk log (current_bulk_media.log): which failures are written
	// -------------------------------------------------------------------

	/** @var int[] apiStatus of every item result returned by the ticks of the last bulkRunAndReadLog() call. */
	private $bulkApiStatuses = array();

	/** Run a bulk queue for one attachment for a fixed number of ticks; returns the bulk log text ('' when none). */
	private function bulkRunAndReadLog( int $attachment_id, int $ticks = 8 ): string {
		$log = SHORTPIXEL_BACKUP_FOLDER . '/current_bulk_media.log';
		@unlink( $log );
		$this->bulkApiStatuses = array();

		$imageModel = \wpSPIO()->filesystem()->getImage( $attachment_id, 'media' );
		( new QueueController( array( 'is_bulk' => true ) ) )->addItemToQueue( $imageModel );
		for ( $tick = 0; $tick < $ticks; $tick++ ) {
			$result = ( new QueueController( array( 'is_bulk' => true ) ) )->processQueue( array( 'media' ) );
			// Through JSON, the way the browser gets it: the item results are objects with guarded properties.
			$result = json_decode( (string) wp_json_encode( $result ) );
			if ( isset( $result->media->results ) && is_array( $result->media->results ) ) {
				foreach ( $result->media->results as $itemResult ) {
					if ( isset( $itemResult->apiStatus ) ) {
						$this->bulkApiStatuses[] = (int) $itemResult->apiStatus;
					}
				}
			}
			$this->backdateQueueItems();
		}

		clearstatcache();
		$text = file_exists( $log ) ? (string) file_get_contents( $log ) : '';
		@unlink( $log );
		return $text;
	}

	/**
	 * During a bulk run, an image that fails with a known FILE error (-102
	 * invalid URL, -106 URL inaccessible, …) is written to the bulk log with
	 * its file name. QueueController::runTick() logs a failed item once it is
	 * done and its fileStatus is ImageModel::FILE_STATUS_ERROR, which
	 * OptimizeController sets for every STATUS_ERROR result.
	 */
	public function test_bulk_log_lists_an_image_failed_with_a_known_file_error() {
		$this->api->forceStatusCode = MockShortPixelApi::CODE_UNREACHABLE;

		$id  = $this->uploadFixture( 'fixture-small.jpg' );
		$log = $this->bulkRunAndReadLog( $id );

		// SENTINEL: the item failed (the API answered for it).
		$this->assertFalse( $this->freshImageModel( $id )->isOptimized(), 'Sentinel: the image was not optimized.' );
		$this->assertNotEmpty( $this->api->requests, 'Sentinel: the API was asked.' );

		$this->assertStringContainsString( wp_basename( get_attached_file( $id ) ), $log, 'The failed image is listed in the bulk log.' );
	}

	/** @return array<string, array{int, string}> API code and the message the plugin shows for it. */
	public function itemLevelApiCodes(): array {
		return array(
			'-302 file no longer available' => array( -302, 'File no longer available on remote system' ),
			'-306 files from several domains' => array( -306, 'Files need to be from a single domain per request.' ),
		);
	}

	/**
	 * -302 and -306 are errors of the item itself: ApiController reports them
	 * as STATUS_ERROR, the item gets FILE_STATUS_ERROR and a bulk run lists it
	 * in the bulk log, with the plugin's own message for the code.
	 *
	 * @dataProvider itemLevelApiCodes
	 */
	public function test_bulk_log_lists_an_image_failed_with_an_item_level_api_code( int $code, string $message ) {
		$this->api->forceStatusCode = $code;

		$id  = $this->uploadFixture( 'fixture-small.jpg' );
		$log = $this->bulkRunAndReadLog( $id );

		// SENTINEL: the item failed (the API answered for it).
		$this->assertFalse( $this->freshImageModel( $id )->isOptimized(), 'Sentinel: the image was not optimized.' );
		$this->assertNotEmpty( $this->api->requests, 'Sentinel: the API was asked.' );

		$this->assertStringContainsString( wp_basename( get_attached_file( $id ) ), $log, 'The failed image is listed in the bulk log.' );
		$this->assertStringContainsString( $message, $log, 'The log line carries the message for this code.' );
		$this->assertSame( 1, substr_count( $log, wp_basename( get_attached_file( $id ) ) ), 'The image is listed once: the item is not retried.' );
	}

	/**
	 * An image the API keeps answering "still working" for is given up once
	 * the queue's retry limit is reached ("Retry Limit reached…", apiStatus
	 * ApiController::ERR_TIMEOUT). Its fileStatus is still
	 * FILE_STATUS_PENDING at that point, so QueueController::runTick() leaves
	 * it out of the bulk log and the user finds no trace of the image there.
	 *
	 * Fixed when: the image name and the retry-limit message are in the log.
	 * Then turn the last assertion around and drop the suffix from the name.
	 */
	public function test_bulk_log_leaves_out_an_image_that_reached_the_retry_limit_pinned_for_deferred_fix() {
		$this->api->waitingRounds = 1000;

		$id    = $this->uploadFixture( 'fixture-small.jpg' );
		$limit = (int) ( new QueueController( array( 'is_bulk' => true ) ) )->getQueue( 'media' )->getShortQ()->getOption( 'retry_limit' );
		$log   = $this->bulkRunAndReadLog( $id, $limit + 5 );

		// SENTINEL: the retry limit was reached and reported for the item.
		$this->assertFalse( $this->freshImageModel( $id )->isOptimized(), 'Sentinel: the image was not optimized.' );
		$this->assertContains( \ShortPixel\Controller\Api\ApiController::ERR_TIMEOUT, $this->bulkApiStatuses, 'Sentinel: a tick reported the retry-limit error for the item.' );

		$this->assertStringNotContainsString( wp_basename( get_attached_file( $id ) ), $log, 'Fixed? The image given up at the retry limit is now in the bulk log.' );
	}

	/**
	 * An image the API answers with an UNRECOGNISED error code is retried
	 * (returnRetry with the raw code) and never gets FILE_STATUS_ERROR, so it
	 * stays out of the bulk log. The log only lists final file errors; it
	 * leaves out temporary and system-level failures by design.
	 */
	public function test_bulk_log_leaves_out_an_image_retried_for_an_unrecognised_api_code() {
		$this->api->forceStatusCode = -204;

		$id  = $this->uploadFixture( 'fixture-small.jpg' );
		$log = $this->bulkRunAndReadLog( $id );

		// SENTINEL: the item was not optimized.
		$this->assertFalse( $this->freshImageModel( $id )->isOptimized(), 'Sentinel: the image was not optimized.' );

		$this->assertStringNotContainsString( wp_basename( get_attached_file( $id ) ), $log, 'A retried, non-file error is not listed in the bulk log.' );
	}
}
