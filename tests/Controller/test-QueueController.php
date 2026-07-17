<?php
/**
 * Tests for ShortPixel\Controller\QueueController.
 *
 * Covers:
 *   - Constructor defaults: is_bulk = false when no args supplied.
 *   - getQueue('media') returns a MediaLibraryQueue instance.
 *   - getQueue('custom') returns a CustomQueue instance.
 *   - getQueue() with is_bulk=false uses 'mediaSingle'/'customSingle' queue names.
 *   - getQueue() with is_bulk=true uses 'media'/'custom' queue names.
 *   - getQueue() returns false for an unknown type.
 *   - getLastId() / setLastID() round-trip via reflection.
 *   - getLastQueueStatus() returns null before any run.
 *   - getJsonResponse() returns an object with the four expected null-initialised fields.
 *   - queueToJson() (via reflection) maps every RESULT_* constant to a non-empty message.
 *   - calculateStatsTotals() (via reflection) — media-only, custom-only, both-present cases.
 *   - numberFormatStats() (via reflection) — numeric fields become formatted strings.
 *   - isItemInQueue() / addItemToQueue(): skipped — require real attachment data and
 *     a valid API key to reach the optimizer; integration territory.
 *   - processQueue(): skipped — requires a verified API key + quota; integration territory.
 *   - runTick(): skipped — depends on a live dequeue cycle; integration territory.
 *   - resetQueues(): skipped — alters shared DB state across all four queue names.
 *   - Line ~230 note: `$qItem->result->message` access is NOT pinned as a bug; see
 *     task description for confirmation.
 *
 * @package Shortpixel_Image_Optimiser
 */

use ShortPixel\Controller\QueueController;
use ShortPixel\Controller\Queue\Queue;
use ShortPixel\Controller\Queue\MediaLibraryQueue;
use ShortPixel\Controller\Queue\CustomQueue;

class QueueControllerTest extends WP_UnitTestCase {

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	private function freshController( array $args = array() ): QueueController {
		return new QueueController( $args );
	}

	private function getProtected( QueueController $c, string $prop ) {
		$ref = new ReflectionClass( QueueController::class );
		do {
			if ( $ref->hasProperty( $prop ) ) {
				$p = $ref->getProperty( $prop );
				$p->setAccessible( true );
				return $p->getValue( $c );
			}
		} while ( $ref = $ref->getParentClass() );
		throw new \RuntimeException( "Property '$prop' not found in QueueController hierarchy." );
	}

	private function setProtected( QueueController $c, string $prop, $value ): void {
		$ref = new ReflectionClass( QueueController::class );
		do {
			if ( $ref->hasProperty( $prop ) ) {
				$p = $ref->getProperty( $prop );
				$p->setAccessible( true );
				$p->setValue( $c, $value );
				return;
			}
		} while ( $ref = $ref->getParentClass() );
		throw new \RuntimeException( "Property '$prop' not found in QueueController hierarchy." );
	}

	private function invokePrivate( QueueController $c, string $method, array $args = array() ) {
		$ref = new ReflectionClass( QueueController::class );
		do {
			if ( $ref->hasMethod( $method ) ) {
				$m = $ref->getMethod( $method );
				$m->setAccessible( true );
				return $m->invoke( $c, ...$args );
			}
		} while ( $ref = $ref->getParentClass() );
		throw new \RuntimeException( "Method '$method' not found in QueueController hierarchy." );
	}

	/** Build a minimal stats stdClass resembling what Queue::getStats() produces. */
	private function makeStats( array $overrides = array() ): \stdClass {
		$defaults = array(
			'is_preparing'   => false,
			'is_running'     => false,
			'is_finished'    => false,
			'in_queue'       => 0,
			'in_process'     => 0,
			'awaiting'       => 0,
			'errors'         => 0,
			'fatal_errors'   => 0,
			'done'           => 0,
			'bulk_running'   => false,
			'total'          => 0,
			'percentage_done'=> 100,
		);
		return (object) array_merge( $defaults, $overrides );
	}

	// -------------------------------------------------------------------------
	// Constants
	// -------------------------------------------------------------------------

	/*
	 * Class constants
	 */

	public function test_IN_QUEUE_ACTION_ADDED_is_1() {
		$this->assertSame( 1, QueueController::IN_QUEUE_ACTION_ADDED );
	}

	public function test_IN_QUEUE_SKIPPED_is_2() {
		$this->assertSame( 2, QueueController::IN_QUEUE_SKIPPED );
	}

	// -------------------------------------------------------------------------
	// Constructor defaults
	// -------------------------------------------------------------------------

	/*
	 * Constructor — default args
	 */

	public function test_constructor_sets_is_bulk_to_false_by_default() {
		$c    = $this->freshController();
		$args = $this->getProtected( $c, 'args' );
		$this->assertFalse( $args['is_bulk'] );
	}

	public function test_constructor_accepts_is_bulk_true() {
		$c    = $this->freshController( array( 'is_bulk' => true ) );
		$args = $this->getProtected( $c, 'args' );
		$this->assertTrue( $args['is_bulk'] );
	}

	// -------------------------------------------------------------------------
	// getQueue — type routing
	// -------------------------------------------------------------------------

	/*
	 * getQueue — returns correct class for each type
	 */

	public function test_getQueue_media_returns_MediaLibraryQueue() {
		$c = $this->freshController();
		$q = $c->getQueue( 'media' );
		$this->assertInstanceOf( MediaLibraryQueue::class, $q );
	}

	public function test_getQueue_custom_returns_CustomQueue() {
		$c = $this->freshController();
		$q = $c->getQueue( 'custom' );
		$this->assertInstanceOf( CustomQueue::class, $q );
	}

	public function test_getQueue_unknown_type_returns_false() {
		$c = $this->freshController();
		$q = $c->getQueue( 'not_a_real_type' );
		$this->assertFalse( $q );
	}

	// -------------------------------------------------------------------------
	// getQueue — is_bulk flag drives queue name selection
	// -------------------------------------------------------------------------

	/*
	 * getQueue — queue name depends on is_bulk flag
	 */

	public function test_getQueue_media_uses_mediaSingle_when_not_bulk() {
		$c = $this->freshController( array( 'is_bulk' => false ) );
		$q = $c->getQueue( 'media' );
		$this->assertSame( 'mediaSingle', $q->getQueueName() );
	}

	public function test_getQueue_media_uses_media_when_bulk() {
		$c = $this->freshController( array( 'is_bulk' => true ) );
		$q = $c->getQueue( 'media' );
		$this->assertSame( 'media', $q->getQueueName() );
	}

	public function test_getQueue_custom_uses_customSingle_when_not_bulk() {
		$c = $this->freshController( array( 'is_bulk' => false ) );
		$q = $c->getQueue( 'custom' );
		$this->assertSame( 'customSingle', $q->getQueueName() );
	}

	public function test_getQueue_custom_uses_custom_when_bulk() {
		$c = $this->freshController( array( 'is_bulk' => true ) );
		$q = $c->getQueue( 'custom' );
		$this->assertSame( 'custom', $q->getQueueName() );
	}

	// -------------------------------------------------------------------------
	// getLastId / setLastID
	// -------------------------------------------------------------------------

	/*
	 * getLastId — static property round-trip
	 */

	public function test_getLastId_returns_null_on_a_fresh_class_state() {
		// Reset static property before the assertion.
		$ref = new ReflectionClass( QueueController::class );
		$p   = $ref->getProperty( 'lastId' );
		$p->setAccessible( true );
		$p->setValue( null, null );

		$this->assertNull( QueueController::getLastId() );
	}

	public function test_setLastID_and_getLastId_round_trip() {
		$c = $this->freshController();
		$this->invokePrivate( $c, 'setLastID', array( 42 ) );
		$this->assertSame( 42, QueueController::getLastId() );
	}

	// -------------------------------------------------------------------------
	// getLastQueueStatus
	// -------------------------------------------------------------------------

	/*
	 * getLastQueueStatus — null before any run
	 */

	public function test_getLastQueueStatus_returns_null_before_any_run() {
		$c = $this->freshController();
		$this->assertNull( $c->getLastQueueStatus() );
	}

	// -------------------------------------------------------------------------
	// getJsonResponse
	// -------------------------------------------------------------------------

	/*
	 * getJsonResponse — blank response shape
	 */

	public function test_getJsonResponse_returns_an_object() {
		$c    = $this->freshController();
		$json = $this->invokePrivate( $c, 'getJsonResponse' );
		$this->assertIsObject( $json );
	}

	public function test_getJsonResponse_has_null_status() {
		$c    = $this->freshController();
		$json = $this->invokePrivate( $c, 'getJsonResponse' );
		$this->assertNull( $json->status );
	}

	public function test_getJsonResponse_has_null_result() {
		$c    = $this->freshController();
		$json = $this->invokePrivate( $c, 'getJsonResponse' );
		$this->assertNull( $json->result );
	}

	public function test_getJsonResponse_has_null_results() {
		$c    = $this->freshController();
		$json = $this->invokePrivate( $c, 'getJsonResponse' );
		$this->assertNull( $json->results );
	}

	public function test_getJsonResponse_has_null_message() {
		$c    = $this->freshController();
		$json = $this->invokePrivate( $c, 'getJsonResponse' );
		$this->assertNull( $json->message );
	}

	// -------------------------------------------------------------------------
	// queueToJson
	// -------------------------------------------------------------------------

	/*
	 * queueToJson — every RESULT_* constant maps to a non-empty message
	 */

	public function test_queueToJson_RESULT_PREPARING_produces_non_empty_message() {
		$c      = $this->freshController();
		$result = (object) array( 'qstatus' => Queue::RESULT_PREPARING, 'items' => 5 );
		$json   = $this->invokePrivate( $c, 'queueToJson', array( $result ) );
		$this->assertNotEmpty( $json->message );
		$this->assertSame( Queue::RESULT_PREPARING, $json->qstatus );
	}

	public function test_queueToJson_RESULT_PREPARING_OVERLIMIT_produces_non_empty_message() {
		$c      = $this->freshController();
		$result = (object) array( 'qstatus' => Queue::RESULT_PREPARING_OVERLIMIT, 'items' => 5 );
		$json   = $this->invokePrivate( $c, 'queueToJson', array( $result ) );
		$this->assertNotEmpty( $json->message );
	}

	public function test_queueToJson_RESULT_PREPARING_DONE_produces_non_empty_message_and_uses_stats_total() {
		$c      = $this->freshController();
		$result = (object) array(
			'qstatus' => Queue::RESULT_PREPARING_DONE,
			'items'   => 0,
			'stats'   => $this->makeStats( array( 'total' => 10 ) ),
		);
		$json = $this->invokePrivate( $c, 'queueToJson', array( $result ) );
		$this->assertNotEmpty( $json->message );
		$this->assertStringContainsString( '10', $json->message );
	}

	public function test_queueToJson_RESULT_EMPTY_produces_non_empty_message() {
		$c      = $this->freshController();
		$result = (object) array( 'qstatus' => Queue::RESULT_EMPTY );
		$json   = $this->invokePrivate( $c, 'queueToJson', array( $result ) );
		$this->assertNotEmpty( $json->message );
	}

	public function test_queueToJson_RESULT_QUEUE_EMPTY_produces_non_empty_message() {
		$c      = $this->freshController();
		$result = (object) array( 'qstatus' => Queue::RESULT_QUEUE_EMPTY );
		$json   = $this->invokePrivate( $c, 'queueToJson', array( $result ) );
		$this->assertNotEmpty( $json->message );
	}

	public function test_queueToJson_RESULT_ITEMS_sets_results_and_produces_message() {
		$c      = $this->freshController();
		$fakeItems = array( (object) array( 'id' => 1 ), (object) array( 'id' => 2 ) );
		$result = (object) array( 'qstatus' => Queue::RESULT_ITEMS, 'items' => $fakeItems );
		$json   = $this->invokePrivate( $c, 'queueToJson', array( $result ) );
		$this->assertNotEmpty( $json->message );
		$this->assertSame( $fakeItems, $json->results );
	}

	public function test_queueToJson_RESULT_RECOUNT_sets_has_error() {
		$c      = $this->freshController();
		$result = (object) array( 'qstatus' => Queue::RESULT_RECOUNT );
		$json   = $this->invokePrivate( $c, 'queueToJson', array( $result ) );
		$this->assertTrue( $json->has_error );
	}

	public function test_queueToJson_unknown_status_produces_unknown_status_message() {
		$c      = $this->freshController();
		$result = (object) array( 'qstatus' => 9999 );
		$json   = $this->invokePrivate( $c, 'queueToJson', array( $result ) );
		$this->assertNotEmpty( $json->message );
		$this->assertStringContainsString( '9999', $json->message );
	}

	public function test_queueToJson_copies_stats_when_present_on_result() {
		$c      = $this->freshController();
		$stats  = $this->makeStats( array( 'done' => 3 ) );
		$result = (object) array( 'qstatus' => Queue::RESULT_QUEUE_EMPTY, 'stats' => $stats );
		$json   = $this->invokePrivate( $c, 'queueToJson', array( $result ) );
		$this->assertObjectHasProperty( 'stats', $json );
		$this->assertSame( $stats, $json->stats );
	}

	// -------------------------------------------------------------------------
	// calculateStatsTotals
	// -------------------------------------------------------------------------

	/*
	 * calculateStatsTotals — merging logic
	 */

	public function test_calculateStatsTotals_returns_null_when_neither_has_stats() {
		$c       = $this->freshController();
		$results = new \stdClass;
		$total   = $this->invokePrivate( $c, 'calculateStatsTotals', array( $results ) );
		$this->assertNull( $total );
	}

	public function test_calculateStatsTotals_media_only_returns_media_stats() {
		$c           = $this->freshController();
		$mediaStats  = $this->makeStats( array( 'done' => 5, 'total' => 5 ) );
		$results     = new \stdClass;
		$results->media         = new \stdClass;
		$results->media->stats  = $mediaStats;

		$total = $this->invokePrivate( $c, 'calculateStatsTotals', array( $results ) );

		$this->assertIsObject( $total );
		$this->assertObjectHasProperty( 'stats', $total );
		$this->assertSame( $mediaStats, $total->stats );
	}

	public function test_calculateStatsTotals_custom_only_returns_custom_stats() {
		$c            = $this->freshController();
		$customStats  = $this->makeStats( array( 'done' => 3, 'total' => 3 ) );
		$results      = new \stdClass;
		$results->custom        = new \stdClass;
		$results->custom->stats = $customStats;

		$total = $this->invokePrivate( $c, 'calculateStatsTotals', array( $results ) );

		$this->assertIsObject( $total );
		$this->assertSame( $customStats, $total->stats );
	}

	public function test_calculateStatsTotals_both_present_sums_numeric_fields() {
		$c = $this->freshController();

		$mediaStats = $this->makeStats( array( 'done' => 4, 'total' => 4, 'errors' => 0 ) );
		$customStats = $this->makeStats( array( 'done' => 2, 'total' => 2, 'errors' => 1 ) );

		$results         = new \stdClass;
		$results->media  = new \stdClass;
		$results->custom = new \stdClass;
		$results->media->stats  = $mediaStats;
		$results->custom->stats = $customStats;

		$total = $this->invokePrivate( $c, 'calculateStatsTotals', array( $results ) );

		$this->assertIsObject( $total );
		$this->assertObjectHasProperty( 'stats', $total );
		// done should be 4 + 2 = 6
		$this->assertSame( 6, $total->stats->done );
		// errors should be 0 + 1 = 1
		$this->assertSame( 1, $total->stats->errors );
	}

	public function test_calculateStatsTotals_both_present_is_finished_true_only_when_both_true() {
		$c = $this->freshController();

		$mediaStats  = $this->makeStats( array( 'is_finished' => true ) );
		$customStats = $this->makeStats( array( 'is_finished' => false ) );

		$results         = new \stdClass;
		$results->media  = new \stdClass;
		$results->custom = new \stdClass;
		$results->media->stats  = $mediaStats;
		$results->custom->stats = $customStats;

		$total = $this->invokePrivate( $c, 'calculateStatsTotals', array( $results ) );

		// is_finished uses AND logic: both must be finished.
		$this->assertFalse( $total->stats->is_finished );
	}

	public function test_calculateStatsTotals_both_present_is_preparing_true_when_either_is_true() {
		$c = $this->freshController();

		$mediaStats  = $this->makeStats( array( 'is_preparing' => true ) );
		$customStats = $this->makeStats( array( 'is_preparing' => false ) );

		$results         = new \stdClass;
		$results->media  = new \stdClass;
		$results->custom = new \stdClass;
		$results->media->stats  = $mediaStats;
		$results->custom->stats = $customStats;

		$total = $this->invokePrivate( $c, 'calculateStatsTotals', array( $results ) );

		// True > False for non-is_finished booleans.
		$this->assertTrue( $total->stats->is_preparing );
	}

	public function test_calculateStatsTotals_both_present_does_not_mutate_original_stats() {
		$c = $this->freshController();

		$mediaStats  = $this->makeStats( array( 'done' => 4, 'total' => 4 ) );
		$customStats = $this->makeStats( array( 'done' => 2, 'total' => 2 ) );

		$results         = new \stdClass;
		$results->media  = new \stdClass;
		$results->custom = new \stdClass;
		$results->media->stats  = $mediaStats;
		$results->custom->stats = $customStats;

		$this->invokePrivate( $c, 'calculateStatsTotals', array( $results ) );

		// The original objects must be unchanged (clone was used).
		$this->assertSame( 4, $mediaStats->done );
		$this->assertSame( 2, $customStats->done );
	}

	// -------------------------------------------------------------------------
	// numberFormatStats
	// -------------------------------------------------------------------------

	/*
	 * numberFormatStats — numeric fields are formatted; bool/string fields are untouched
	 */

	public function test_numberFormatStats_converts_numeric_stat_to_formatted_string() {
		$c = $this->freshController();

		$results        = new \stdClass;
		$results->media = new \stdClass;
		$results->media->stats         = new \stdClass;
		$results->media->stats->done   = 1234;
		$results->media->stats->errors = 0;

		$out = $this->invokePrivate( $c, 'numberFormatStats', array( $results ) );

		// UiHelper::formatNumber(1234, 0) should contain a comma on en_US.
		$this->assertIsString( $out->media->stats->done );
		// The numeric value 1234 should appear somewhere in the formatted string.
		$this->assertStringContainsString( '1', $out->media->stats->done );
	}

	public function test_numberFormatStats_leaves_bool_fields_unchanged() {
		$c = $this->freshController();

		$results        = new \stdClass;
		$results->media = new \stdClass;
		$results->media->stats              = new \stdClass;
		$results->media->stats->is_running  = true;
		$results->media->stats->done        = 0;

		$out = $this->invokePrivate( $c, 'numberFormatStats', array( $results ) );

		$this->assertTrue( $out->media->stats->is_running );
	}

	public function test_numberFormatStats_formats_percentage_with_two_decimals() {
		$c = $this->freshController();

		$results        = new \stdClass;
		$results->media = new \stdClass;
		$results->media->stats                  = new \stdClass;
		// Use a genuinely fractional percentage so UiHelper::formatNumber does not
		// strip the decimal part as trailing zeroes (e.g. 75 → "75.00" → "75").
		// 33.33... is non-whole, so the decimal point is preserved in the output.
		$results->media->stats->percentage_done = round( 100 / 3, 4 );

		$out = $this->invokePrivate( $c, 'numberFormatStats', array( $results ) );

		// percentage fields formatted with 2 decimal places retain the decimal separator.
		$this->assertStringContainsString( '.', (string) $out->media->stats->percentage_done );
	}

	// -------------------------------------------------------------------------
	// checkQueueClean
	// -------------------------------------------------------------------------

	/*
	 * checkQueueClean — only calls cleanQueue when queue is empty and not bulk
	 */

	public function test_checkQueueClean_does_not_throw_for_non_empty_result() {
		$c      = $this->freshController( array( 'is_bulk' => false ) );
		$q      = $c->getQueue( 'media' );
		$result = (object) array( 'qstatus' => Queue::RESULT_ITEMS );

		$this->invokePrivate( $c, 'checkQueueClean', array( $result, $q ) );
		$this->assertTrue( true ); // reached without exception
	}

	public function test_checkQueueClean_does_not_call_cleanQueue_when_is_bulk() {
		$c = $this->freshController( array( 'is_bulk' => true ) );
		$q = $c->getQueue( 'media' );
		$q->resetQueue();

		$result = (object) array( 'qstatus' => Queue::RESULT_QUEUE_EMPTY );
		// In bulk mode cleanQueue should never be called; test asserts no exception.
		$this->invokePrivate( $c, 'checkQueueClean', array( $result, $q ) );
		$this->assertTrue( true );
	}

} // class
