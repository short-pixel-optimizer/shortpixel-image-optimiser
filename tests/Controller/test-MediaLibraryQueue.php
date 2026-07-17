<?php
/**
 * Tests for ShortPixel\Controller\Queue\MediaLibraryQueue.
 *
 * Covers:
 *   - getType() returns 'media'.
 *   - Constructor sets queueName to 'Media' by default.
 *   - Constructor accepts a custom queue name (e.g. 'mediaSingle').
 *   - getFilterQueryData() shape: correct keys, correct date_field, and
 *     that the base_query references the wp_posts table and uses 'attachment'.
 *   - cacheName is 'MediaCache'.
 *   - Default options array contains expected keys.
 *   - createNewBulk() returns an array with the merged options.
 *   - createNewBulk() strips 'filters' before passing to parent (no filters key in result).
 *   - The 'filters' option key in the base options is an empty array.
 *
 * Out of scope (and why):
 *   - prepare() / prepareBulkRestore() / prepareUndoAI() — run DB queries against
 *     postmeta / shortpixel_postmeta; require seeded attachment data; integration territory.
 *   - addFilters() date → ID translation — live DB query with date constraints not
 *     seeded in the WP unit-test install.
 *   - queryPostMeta() / queryOptimizedItems() / queryAiItems() — private, live DB.
 *
 * @package Shortpixel_Image_Optimiser
 */

use ShortPixel\Controller\Queue\MediaLibraryQueue;
use ShortPixel\Controller\Queue\Queue;

class MediaLibraryQueueTest extends WP_UnitTestCase {

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/** Fresh instance for each test to avoid cross-test ShortQ state. */
	private function freshQueue( string $name = 'mediaSingle' ): MediaLibraryQueue {
		return new MediaLibraryQueue( $name );
	}

	private function getProtected( MediaLibraryQueue $q, string $prop ) {
		$ref = new ReflectionClass( MediaLibraryQueue::class );
		// Walk up the hierarchy if the property is declared in Queue.
		do {
			if ( $ref->hasProperty( $prop ) ) {
				$p = $ref->getProperty( $prop );
				$p->setAccessible( true );
				return $p->getValue( $q );
			}
		} while ( $ref = $ref->getParentClass() );
		throw new \RuntimeException( "Property '$prop' not found in MediaLibraryQueue hierarchy." );
	}

	private function invokeProtected( MediaLibraryQueue $q, string $method, array $args = array() ) {
		$ref = new ReflectionClass( MediaLibraryQueue::class );
		do {
			if ( $ref->hasMethod( $method ) ) {
				$m = $ref->getMethod( $method );
				$m->setAccessible( true );
				return $m->invoke( $q, ...$args );
			}
		} while ( $ref = $ref->getParentClass() );
		throw new \RuntimeException( "Method '$method' not found in MediaLibraryQueue hierarchy." );
	}

	// -------------------------------------------------------------------------
	// getType
	// -------------------------------------------------------------------------

	/*
	 * getType — must always return 'media'
	 */

	public function test_getType_returns_media() {
		$this->assertSame( 'media', $this->freshQueue()->getType() );
	}

	public function test_getType_returns_media_for_mediaSingle_queue() {
		$this->assertSame( 'media', $this->freshQueue( 'mediaSingle' )->getType() );
	}

	// -------------------------------------------------------------------------
	// Constructor / queueName
	// -------------------------------------------------------------------------

	/*
	 * Constructor — queue name is stored and retrievable
	 */

	public function test_constructor_default_queue_name_is_Media() {
		$q = new MediaLibraryQueue( 'Media' );
		$this->assertSame( 'Media', $q->getQueueName() );
	}

	public function test_constructor_accepts_custom_queue_name() {
		$q = new MediaLibraryQueue( 'mediaSingle' );
		$this->assertSame( 'mediaSingle', $q->getQueueName() );
	}

	// -------------------------------------------------------------------------
	// cacheName
	// -------------------------------------------------------------------------

	/*
	 * cacheName — must be 'MediaCache'
	 */

	public function test_cacheName_is_MediaCache() {
		$q = $this->freshQueue();
		$this->assertSame( 'MediaCache', $this->getProtected( $q, 'cacheName' ) );
	}

	// -------------------------------------------------------------------------
	// Default options
	// -------------------------------------------------------------------------

	/*
	 * Default options array — key presence and sensible defaults
	 */

	public function test_options_contains_numitems_key() {
		$q = $this->freshQueue();
		$opts = $this->getProtected( $q, 'options' );
		$this->assertArrayHasKey( 'numitems', $opts );
	}

	public function test_options_contains_mode_key() {
		$q = $this->freshQueue();
		$opts = $this->getProtected( $q, 'options' );
		$this->assertArrayHasKey( 'mode', $opts );
	}

	public function test_options_contains_process_timeout_key() {
		$q = $this->freshQueue();
		$opts = $this->getProtected( $q, 'options' );
		$this->assertArrayHasKey( 'process_timeout', $opts );
	}

	public function test_options_contains_retry_limit_key() {
		$q = $this->freshQueue();
		$opts = $this->getProtected( $q, 'options' );
		$this->assertArrayHasKey( 'retry_limit', $opts );
	}

	public function test_options_contains_enqueue_limit_key() {
		$q = $this->freshQueue();
		$opts = $this->getProtected( $q, 'options' );
		$this->assertArrayHasKey( 'enqueue_limit', $opts );
	}

	public function test_options_filters_default_is_empty_array() {
		$q = $this->freshQueue();
		$opts = $this->getProtected( $q, 'options' );
		$this->assertArrayHasKey( 'filters', $opts );
		$this->assertIsArray( $opts['filters'] );
		$this->assertEmpty( $opts['filters'] );
	}

	public function test_options_numitems_is_positive_integer() {
		$q = $this->freshQueue();
		$opts = $this->getProtected( $q, 'options' );
		$this->assertIsInt( $opts['numitems'] );
		$this->assertGreaterThan( 0, $opts['numitems'] );
	}

	// -------------------------------------------------------------------------
	// getFilterQueryData
	// -------------------------------------------------------------------------

	/*
	 * getFilterQueryData — shape and content validation
	 */

	public function test_getFilterQueryData_returns_an_array() {
		$q    = $this->freshQueue();
		$data = $this->invokeProtected( $q, 'getFilterQueryData' );
		$this->assertIsArray( $data );
	}

	public function test_getFilterQueryData_has_date_field_key() {
		$q    = $this->freshQueue();
		$data = $this->invokeProtected( $q, 'getFilterQueryData' );
		$this->assertArrayHasKey( 'date_field', $data );
	}

	public function test_getFilterQueryData_date_field_is_POST_DATE() {
		$q    = $this->freshQueue();
		$data = $this->invokeProtected( $q, 'getFilterQueryData' );
		$this->assertSame( 'POST_DATE', $data['date_field'] );
	}

	public function test_getFilterQueryData_has_base_query_key() {
		$q    = $this->freshQueue();
		$data = $this->invokeProtected( $q, 'getFilterQueryData' );
		$this->assertArrayHasKey( 'base_query', $data );
	}

	public function test_getFilterQueryData_base_query_references_posts_table() {
		global $wpdb;
		$q    = $this->freshQueue();
		$data = $this->invokeProtected( $q, 'getFilterQueryData' );
		$this->assertStringContainsString( $wpdb->posts, $data['base_query'] );
	}

	public function test_getFilterQueryData_base_query_selects_ID() {
		$q    = $this->freshQueue();
		$data = $this->invokeProtected( $q, 'getFilterQueryData' );
		$this->assertStringContainsString( 'SELECT ID', $data['base_query'] );
	}

	public function test_getFilterQueryData_base_prepare_contains_attachment() {
		$q    = $this->freshQueue();
		$data = $this->invokeProtected( $q, 'getFilterQueryData' );
		$this->assertArrayHasKey( 'base_prepare', $data );
		$this->assertContains( 'attachment', $data['base_prepare'] );
	}

	// -------------------------------------------------------------------------
	// createNewBulk
	// -------------------------------------------------------------------------

	/*
	 * createNewBulk — returns merged options and strips 'filters'
	 */

	public function test_createNewBulk_returns_an_array() {
		$q      = $this->freshQueue( 'testBulkReturn' . uniqid() );
		$q->resetQueue();
		$result = $q->createNewBulk( array( 'doMedia' => true ) );
		$this->assertIsArray( $result );
	}

	/**
	 * PINNED — production bug.
	 *
	 * Bug: class/Controller/Queue/MediaLibraryQueue.php::createNewBulk() (line ~119).
	 * The method calls unset($args['filters']) on the caller's $args before merging,
	 * but then merges with $this->options which always contains 'filters' => [] as a
	 * default (declared at line ~39).  The array_merge therefore always re-introduces
	 * 'filters' into the returned array — the unset has no visible effect on the result.
	 * Fix: also unset 'filters' from $options after the array_merge, before returning.
	 */
	public function test_createNewBulk_strips_filters_key_from_returned_options_pinned_for_deferred_fix() {
		$q      = $this->freshQueue( 'testBulkFilters' . uniqid() );
		$q->resetQueue();
		// Provide a 'filters' key; it should be consumed by addFilters() and absent in result.
		// We pass an empty filters array so addFilters() is a no-op (no dates to resolve).
		$result = $q->createNewBulk( array( 'filters' => array() ) );
		$this->assertArrayNotHasKey( 'filters', $result );
	}

	public function test_createNewBulk_merges_caller_args_into_returned_options() {
		$q      = $this->freshQueue( 'testBulkMerge' . uniqid() );
		$q->resetQueue();
		$result = $q->createNewBulk( array( 'doMedia' => false, 'doAi' => true ) );
		$this->assertArrayHasKey( 'doMedia', $result );
		$this->assertFalse( $result['doMedia'] );
	}

} // class
