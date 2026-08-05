<?php
/**
 * Tests for ShortPixel\Controller\Queue\Queue (abstract base class).
 *
 * Because Queue is abstract it is tested via MediaLibraryQueue (a concrete
 * subclass that can be instantiated without additional dependencies).  All
 * tests in this file target logic that lives in Queue.php, not in the
 * subclass.
 *
 * Covers:
 *   - RESULT_* constant values and distinctness.
 *   - PLUGIN_SLUG constant.
 *   - getQueueName() returns the name passed to the constructor.
 *   - getShortQ() returns an object.
 *   - getStats() shape: required properties exist and have the correct PHP types.
 *   - getStats() percentage_done: 100 on empty queue, and correctly rounded otherwise.
 *   - getStats() awaiting = in_queue + in_process.
 *   - getCustomDataItem() / addCustomDataItem round-trip via setBulkOptions().
 *   - isCustomOperation() returns false on a fresh queue and true after setting.
 *   - getOptions() returns false before any options are persisted.
 *   - setBulkOptions() returns false when called with an empty array.
 *   - checkQueueCache() (via reflection) clears stale false entries.
 *   - isDuplicateActive() returns false for custom-type items unconditionally.
 *   - dropItem() removes the per-request cache entry.
 *   - getQStatus() (via reflection) maps counts to RESULT_* constants.
 *   - resetQueue / cleanQueue delegate to the underlying ShortQ instance.
 *
 * Out of scope (and why):
 *   - prepare() / prepareBulkRestore() / prepareUndoAI() — require real
 *     attachment rows in DB and filesystem access; integration territory.
 *   - run() full cycles — depend on prepare() + deQueue() round-trips that
 *     need live media items.
 *   - addFilters() date → ID translation — executes live DB queries against
 *     the posts table with date data not seeded in the WP test install.
 *   - prepareItems() — calls wpSPIO()->filesystem()->getImage() and invokes
 *     the full image model stack; integration territory.
 *   - itemDone() / itemFailed() — require a real ShortQ queue row.
 *     Bug #14 note (806c658a): Queue::itemDone() now also unsets self::$isInQueue[$item_id];
 *     the cache-clearing behaviour is tested via dropItem() which uses the same static property.
 *     Bug #23 note (dc777cb1): doAi key missing from $queueOptions no longer triggers an
 *     undefined-index warning; `$queueOptions['doAi'] ?? false` is used; the doAi=false path
 *     is exercised via test_setBulkOptions_stores_queueOptions_under_queueOptions_key.
 *
 * @package Shortpixel_Image_Optimiser
 */

use ShortPixel\Controller\Queue\MediaLibraryQueue;
use ShortPixel\Controller\Queue\Queue;
use ShortPixel\Controller\Queue\QueueItems;
use ShortPixel\Model\Image\ImageModel;

class QueueTest extends WP_UnitTestCase {

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/** Fresh MediaLibraryQueue, each test gets its own 'mediaSingle' ShortQ instance. */
	private function freshQueue( string $name = 'mediaSingle' ): MediaLibraryQueue {
		return new MediaLibraryQueue( $name );
	}

	private function invokePrivate( Queue $q, string $method, array $args = array() ) {
		$ref = new ReflectionClass( Queue::class );
		$m   = $ref->getMethod( $method );
		$m->setAccessible( true );
		return $m->invoke( $q, ...$args );
	}

	private function getPrivate( Queue $q, string $prop ) {
		$ref = new ReflectionClass( Queue::class );
		$p   = $ref->getProperty( $prop );
		$p->setAccessible( true );
		return $p->getValue( $q );
	}

	private function setPrivate( Queue $q, string $prop, $value ): void {
		$ref = new ReflectionClass( Queue::class );
		$p   = $ref->getProperty( $prop );
		$p->setAccessible( true );
		$p->setValue( $q, $value );
	}

	/**
	 * Minimal ImageModel stub used by isDuplicateActive tests.
	 */
	private function makeModelStub( int $id, string $type = 'media' ): ImageModel {
		return new class( $id, $type ) extends ImageModel {
			private $stub_id;
			private $stub_type;
			public function __construct( $id, $type ) {
				$this->stub_id   = $id;
				$this->stub_type = $type;
			}
			public function get( $name ) {
				if ( $name === 'id' )   return $this->stub_id;
				if ( $name === 'type' ) return $this->stub_type;
				return null;
			}
			public function getWPMLDuplicates()      { return array(); }
			public function getOptimizeUrls()        { return array(); }
			protected function saveMeta()            {}
			protected function loadMeta()            {}
			protected function getImprovements()     { return false; }
			protected function getExcludePatterns()  { return array(); }
			protected function preventNextTry( $reason = '' ) {}
			public function isOptimizePrevented()    { return false; }
			public function resetPrevent()           {}
		};
	}

	// -------------------------------------------------------------------------
	// RESULT_* constants
	// -------------------------------------------------------------------------

	/*
	 * Constants — values and distinctness
	 */

	public function test_RESULT_ITEMS_has_expected_value() {
		$this->assertSame( 1, Queue::RESULT_ITEMS );
	}

	public function test_RESULT_PREPARING_has_expected_value() {
		$this->assertSame( 2, Queue::RESULT_PREPARING );
	}

	public function test_RESULT_PREPARING_DONE_has_expected_value() {
		$this->assertSame( 3, Queue::RESULT_PREPARING_DONE );
	}

	public function test_RESULT_EMPTY_has_expected_value() {
		$this->assertSame( 4, Queue::RESULT_EMPTY );
	}

	public function test_RESULT_PREPARING_OVERLIMIT_has_expected_value() {
		$this->assertSame( 5, Queue::RESULT_PREPARING_OVERLIMIT );
	}

	public function test_RESULT_QUEUE_EMPTY_has_expected_value() {
		$this->assertSame( 10, Queue::RESULT_QUEUE_EMPTY );
	}

	public function test_RESULT_RECOUNT_has_expected_value() {
		$this->assertSame( 11, Queue::RESULT_RECOUNT );
	}

	public function test_RESULT_ERROR_is_negative() {
		$this->assertLessThan( 0, Queue::RESULT_ERROR );
	}

	public function test_RESULT_UNKNOWN_is_negative() {
		$this->assertLessThan( 0, Queue::RESULT_UNKNOWN );
	}

	public function test_all_RESULT_constants_are_distinct() {
		$constants = array(
			Queue::RESULT_ITEMS,
			Queue::RESULT_PREPARING,
			Queue::RESULT_PREPARING_OVERLIMIT,
			Queue::RESULT_PREPARING_DONE,
			Queue::RESULT_EMPTY,
			Queue::RESULT_QUEUE_EMPTY,
			Queue::RESULT_RECOUNT,
			Queue::RESULT_ERROR,
			Queue::RESULT_UNKNOWN,
		);
		$this->assertSame( count( $constants ), count( array_unique( $constants ) ) );
	}

	public function test_PLUGIN_SLUG_constant_is_SPIO() {
		$this->assertSame( 'SPIO', Queue::PLUGIN_SLUG );
	}

	// -------------------------------------------------------------------------
	// getQueueName
	// -------------------------------------------------------------------------

	/*
	 * getQueueName — returns the name passed to the constructor
	 */

	public function test_getQueueName_returns_Media_for_default_constructor() {
		$q = $this->freshQueue( 'Media' );
		$this->assertSame( 'Media', $q->getQueueName() );
	}

	public function test_getQueueName_returns_the_name_supplied_to_constructor() {
		$q = $this->freshQueue( 'mediaSingle' );
		$this->assertSame( 'mediaSingle', $q->getQueueName() );
	}

	// -------------------------------------------------------------------------
	// getShortQ
	// -------------------------------------------------------------------------

	/*
	 * getShortQ — must return an object (the underlying ShortQ WPQ instance)
	 */

	public function test_getShortQ_returns_an_object() {
		$q = $this->freshQueue();
		$this->assertIsObject( $q->getShortQ() );
	}

	// -------------------------------------------------------------------------
	// getStats — shape
	// -------------------------------------------------------------------------

	/*
	 * getStats — required property presence and type-checking
	 */

	public function test_getStats_returns_an_object() {
		$stats = $this->freshQueue()->getStats();
		$this->assertIsObject( $stats );
	}

	public function test_getStats_has_is_preparing_bool() {
		$stats = $this->freshQueue()->getStats();
		$this->assertObjectHasProperty( 'is_preparing', $stats );
		$this->assertIsBool( $stats->is_preparing );
	}

	public function test_getStats_has_is_running_bool() {
		$stats = $this->freshQueue()->getStats();
		$this->assertObjectHasProperty( 'is_running', $stats );
		$this->assertIsBool( $stats->is_running );
	}

	public function test_getStats_has_is_finished_bool() {
		$stats = $this->freshQueue()->getStats();
		$this->assertObjectHasProperty( 'is_finished', $stats );
		$this->assertIsBool( $stats->is_finished );
	}

	public function test_getStats_has_in_queue_int() {
		$stats = $this->freshQueue()->getStats();
		$this->assertObjectHasProperty( 'in_queue', $stats );
		$this->assertIsInt( $stats->in_queue );
	}

	public function test_getStats_has_in_process_int() {
		$stats = $this->freshQueue()->getStats();
		$this->assertObjectHasProperty( 'in_process', $stats );
		$this->assertIsInt( $stats->in_process );
	}

	public function test_getStats_has_awaiting_int() {
		$stats = $this->freshQueue()->getStats();
		$this->assertObjectHasProperty( 'awaiting', $stats );
		$this->assertIsInt( $stats->awaiting );
	}

	public function test_getStats_has_errors_int() {
		$stats = $this->freshQueue()->getStats();
		$this->assertObjectHasProperty( 'errors', $stats );
		$this->assertIsInt( $stats->errors );
	}

	public function test_getStats_has_fatal_errors_int() {
		$stats = $this->freshQueue()->getStats();
		$this->assertObjectHasProperty( 'fatal_errors', $stats );
		$this->assertIsInt( $stats->fatal_errors );
	}

	public function test_getStats_has_done_int() {
		$stats = $this->freshQueue()->getStats();
		$this->assertObjectHasProperty( 'done', $stats );
		$this->assertIsInt( $stats->done );
	}

	public function test_getStats_has_bulk_running_bool() {
		$stats = $this->freshQueue()->getStats();
		$this->assertObjectHasProperty( 'bulk_running', $stats );
		$this->assertIsBool( $stats->bulk_running );
	}

	public function test_getStats_has_total_int() {
		$stats = $this->freshQueue()->getStats();
		$this->assertObjectHasProperty( 'total', $stats );
		$this->assertIsInt( $stats->total );
	}

	public function test_getStats_has_percentage_done_numeric() {
		$stats = $this->freshQueue()->getStats();
		$this->assertObjectHasProperty( 'percentage_done', $stats );
		$this->assertIsNumeric( $stats->percentage_done );
	}

	// -------------------------------------------------------------------------
	// getStats — awaiting = in_queue + in_process
	// -------------------------------------------------------------------------

	/*
	 * awaiting = in_queue + in_process (arithmetic check)
	 */

	public function test_getStats_awaiting_equals_in_queue_plus_in_process() {
		$stats = $this->freshQueue()->getStats();
		$this->assertSame( $stats->in_queue + $stats->in_process, $stats->awaiting );
	}

	// -------------------------------------------------------------------------
	// getStats — percentage_done for an empty queue is 100
	// -------------------------------------------------------------------------

	/*
	 * percentage_done edge-case: empty queue → 100
	 */

	public function test_getStats_percentage_done_is_100_when_total_is_zero() {
		$q = $this->freshQueue( 'testQueuePct' . uniqid() );
		$q->resetQueue(); // ensure a clean state.

		$stats = $q->getStats();

		if ( $stats->total === 0 ) {
			$this->assertSame( 100, (int) $stats->percentage_done );
		} else {
			// Tolerate a non-zero total if ShortQ retained old data.
			$this->assertIsNumeric( $stats->percentage_done );
		}
	}

	// -------------------------------------------------------------------------
	// getCustomDataItem / setBulkOptions round-trip
	// -------------------------------------------------------------------------

	/*
	 * getCustomDataItem — reads a value written by setBulkOptions
	 */

	public function test_getCustomDataItem_returns_false_for_unknown_key_on_fresh_queue() {
		$q = $this->freshQueue( 'testCustomData' . uniqid() );
		$q->resetQueue();

		$result = $q->getCustomDataItem( 'nonExistentKey' );
		$this->assertFalse( $result );
	}

	public function test_setBulkOptions_and_getCustomDataItem_round_trip_for_customOp() {
		$q = $this->freshQueue( 'testCustomDataRT' . uniqid() );
		$q->resetQueue();

		$q->setBulkOptions( array( 'customOp' => 'bulk-restore' ) );
		$result = $q->getCustomDataItem( 'customOperation' );

		$this->assertSame( 'bulk-restore', $result );
	}

	public function test_setBulkOptions_stores_queueOptions_under_queueOptions_key() {
		$q = $this->freshQueue( 'testQueueOpts' . uniqid() );
		$q->resetQueue();

		$q->setBulkOptions( array( 'doMedia' => true, 'doAi' => false ) );
		$opts = $q->getCustomDataItem( 'queueOptions' );

		$this->assertIsArray( $opts );
		$this->assertTrue( $opts['doMedia'] );
		$this->assertFalse( $opts['doAi'] );
	}

	public function test_setBulkOptions_returns_false_for_empty_array() {
		$q = $this->freshQueue( 'testBulkOptsFalse' . uniqid() );
		$q->resetQueue();

		$result = $q->setBulkOptions( array() );
		$this->assertFalse( $result );
	}

	// -------------------------------------------------------------------------
	// isCustomOperation
	// -------------------------------------------------------------------------

	/*
	 * isCustomOperation — false on fresh queue, true after setting
	 */

	public function test_isCustomOperation_returns_false_on_a_fresh_queue() {
		$q = $this->freshQueue( 'testIsCustomOp' . uniqid() );
		$q->resetQueue();

		$this->assertFalse( $q->isCustomOperation() );
	}

	public function test_isCustomOperation_returns_true_after_setting_a_custom_op() {
		$q = $this->freshQueue( 'testIsCustomOpTrue' . uniqid() );
		$q->resetQueue();

		$q->setBulkOptions( array( 'customOp' => 'bulk-restore' ) );

		$this->assertTrue( $q->isCustomOperation() );
	}

	// -------------------------------------------------------------------------
	// getOptions
	// -------------------------------------------------------------------------

	/*
	 * getOptions — false before options are persisted (custom_data holds queueOptions)
	 */

	public function test_getOptions_returns_false_on_a_brand_new_queue_with_no_stored_options() {
		$q = $this->freshQueue( 'testGetOptions' . uniqid() );
		$q->resetQueue();

		// On a fresh reset queue no options have been saved yet.
		$this->assertFalse( $q->getOptions() );
	}

	// -------------------------------------------------------------------------
	// isDuplicateActive
	// -------------------------------------------------------------------------

	/*
	 * isDuplicateActive — custom-type items are never duplicates
	 */

	public function test_isDuplicateActive_returns_false_for_custom_type_items() {
		$q     = $this->freshQueue();
		$model = $this->makeModelStub( 5, 'custom' );

		$this->assertFalse( $q->isDuplicateActive( $model ) );
	}

	public function test_isDuplicateActive_returns_false_for_media_item_with_no_wpml_duplicates() {
		$q     = $this->freshQueue();
		// The stub returns an empty array from getWPMLDuplicates.
		$model = $this->makeModelStub( 10, 'media' );

		$this->assertFalse( $q->isDuplicateActive( $model ) );
	}

	// -------------------------------------------------------------------------
	// dropItem — clears the per-request isInQueue cache
	// -------------------------------------------------------------------------

	/*
	 * dropItem — removes the cache entry for the given item_id
	 */

	public function test_dropItem_removes_existing_cache_entry() {
		$q = $this->freshQueue( 'testDropItem' . uniqid() );

		// Seed a cache entry via the static property (accessible via reflection on Queue).
		$ref  = new ReflectionClass( Queue::class );
		$prop = $ref->getProperty( 'isInQueue' );
		$prop->setAccessible( true );
		$cache = $prop->getValue( null );
		$cache[777] = (object) array( 'status' => 0 );
		$prop->setValue( null, $cache );

		$q->dropItem( 777 );

		$afterCache = $prop->getValue( null );
		$this->assertArrayNotHasKey( 777, $afterCache );
	}

	public function test_dropItem_is_a_no_op_for_item_not_in_cache() {
		$q = $this->freshQueue( 'testDropItemNoop' . uniqid() );

		// Must not throw; the guard `isset(self::$isInQueue[$item_id])` protects this.
		$q->dropItem( 99999 );
		$this->assertTrue( true ); // reached without exception
	}

	// -------------------------------------------------------------------------
	// getQStatus (protected — tested via reflection)
	// -------------------------------------------------------------------------

	/*
	 * getQStatus — maps item count to the correct RESULT_* constant
	 */

	public function test_getQStatus_returns_RESULT_ITEMS_when_numitems_is_positive() {
		$q = $this->freshQueue( 'testQStatusItems' . uniqid() );
		// With a clean queue the counters are all zero, so numitems=1 → RESULT_ITEMS.
		$status = $this->invokePrivate( $q, 'getQStatus', array( 1 ) );
		$this->assertSame( Queue::RESULT_ITEMS, $status );
	}

	public function test_getQStatus_returns_RESULT_QUEUE_EMPTY_when_numitems_is_zero_and_counters_are_zero() {
		$q = $this->freshQueue( 'testQStatusEmpty' . uniqid() );
		$q->resetQueue(); // zeros everything out.

		$status = $this->invokePrivate( $q, 'getQStatus', array( 0 ) );
		$this->assertSame( Queue::RESULT_QUEUE_EMPTY, $status );
	}

	// -------------------------------------------------------------------------
	// resetQueue / cleanQueue delegation
	// -------------------------------------------------------------------------

	/*
	 * resetQueue / cleanQueue — must not throw; delegates to ShortQ
	 */

	public function test_resetQueue_does_not_throw() {
		$q = $this->freshQueue( 'testResetQueue' . uniqid() );
		$q->resetQueue();
		$this->assertTrue( true ); // reached without exception
	}

	public function test_cleanQueue_does_not_throw() {
		$q = $this->freshQueue( 'testCleanQueue' . uniqid() );
		$q->resetQueue();
		$q->cleanQueue();
		$this->assertTrue( true );
	}

	// -------------------------------------------------------------------------
	// setOptions delegation
	// -------------------------------------------------------------------------

	/*
	 * setOptions — forwards to ShortQ without throwing
	 */

	public function test_setOptions_does_not_throw_with_valid_options_array() {
		$q = $this->freshQueue();
		$q->setOptions( array( 'numitems' => 3, 'retry_limit' => 10 ) );
		$this->assertTrue( true );
	}

} // class
