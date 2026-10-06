<?php
/**
 * Tests for ShortPixel\Controller\Queue\CustomQueue.
 *
 * Covers:
 *   - getType() returns 'custom'.
 *   - Constructor sets queueName to 'Custom' by default.
 *   - Constructor accepts a custom queue name.
 *   - cacheName is 'CustomCache'.
 *   - Default ShortQ options are correctly applied (numitems, retry_limit, enqueue_limit).
 *   - getFilterQueryData() shape: correct keys, date_field = 'ts_added', and that the
 *     base_query references the shortpixel_meta table without a post-type constraint.
 *   - prepareUndoAI() returns an empty array (intentionally not implemented).
 *   - createNewBulk() returns an array with the merged options.
 *   - createNewBulk() strips the 'filters' key from the returned options.
 *
 * Out of scope (and why):
 *   - prepare() / prepareBulkRestore() — run live DB queries against shortpixel_meta
 *     and shortpixel_folders; require seeded custom-folder data; integration territory.
 *   - queryItems() / queryOptimizedItems() — private, live DB.
 *   - addFilters() date → ID translation — live DB query; skipped.
 *
 * @package Shortpixel_Image_Optimiser
 */

use ShortPixel\Controller\Queue\CustomQueue;
use ShortPixel\Controller\Queue\Queue;

class CustomQueueTest extends WP_UnitTestCase {

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	private function freshQueue( string $name = 'customSingle' ): CustomQueue {
		return new CustomQueue( $name );
	}

	private function getProtected( CustomQueue $q, string $prop ) {
		$ref = new ReflectionClass( CustomQueue::class );
		do {
			if ( $ref->hasProperty( $prop ) ) {
				$p = $ref->getProperty( $prop );
				$p->setAccessible( true );
				return $p->getValue( $q );
			}
		} while ( $ref = $ref->getParentClass() );
		throw new \RuntimeException( "Property '$prop' not found in CustomQueue hierarchy." );
	}

	private function invokeProtected( CustomQueue $q, string $method, array $args = array() ) {
		$ref = new ReflectionClass( CustomQueue::class );
		do {
			if ( $ref->hasMethod( $method ) ) {
				$m = $ref->getMethod( $method );
				$m->setAccessible( true );
				return $m->invoke( $q, ...$args );
			}
		} while ( $ref = $ref->getParentClass() );
		throw new \RuntimeException( "Method '$method' not found in CustomQueue hierarchy." );
	}

	// -------------------------------------------------------------------------
	// getType
	// -------------------------------------------------------------------------

	/*
	 * getType — always returns 'custom'
	 */

	public function test_getType_returns_custom() {
		$this->assertSame( 'custom', $this->freshQueue()->getType() );
	}

	public function test_getType_returns_custom_for_customSingle_queue() {
		$this->assertSame( 'custom', $this->freshQueue( 'customSingle' )->getType() );
	}

	// -------------------------------------------------------------------------
	// Constructor / queueName
	// -------------------------------------------------------------------------

	/*
	 * Constructor — queue name is stored and retrievable
	 */

	public function test_constructor_default_queue_name_is_Custom() {
		$q = new CustomQueue( 'Custom' );
		$this->assertSame( 'Custom', $q->getQueueName() );
	}

	public function test_constructor_accepts_custom_queue_name() {
		$q = new CustomQueue( 'customSingle' );
		$this->assertSame( 'customSingle', $q->getQueueName() );
	}

	// -------------------------------------------------------------------------
	// cacheName
	// -------------------------------------------------------------------------

	/*
	 * cacheName — must be 'CustomCache'
	 */

	public function test_cacheName_is_CustomCache() {
		$q = $this->freshQueue();
		$this->assertSame( 'CustomCache', $this->getProtected( $q, 'cacheName' ) );
	}

	// -------------------------------------------------------------------------
	// ShortQ options applied by constructor
	// -------------------------------------------------------------------------

	/*
	 * ShortQ option defaults — verify the values flow through to the underlying WPQ
	 */

	public function test_shortq_option_numitems_is_5() {
		$q = $this->freshQueue();
		$this->assertSame( 5, $q->getShortQ()->getOption( 'numitems' ) );
	}

	public function test_shortq_option_retry_limit_is_20() {
		$q = $this->freshQueue();
		$this->assertSame( 20, $q->getShortQ()->getOption( 'retry_limit' ) );
	}

	public function test_shortq_option_enqueue_limit_is_120() {
		$q = $this->freshQueue();
		$this->assertSame( 120, $q->getShortQ()->getOption( 'enqueue_limit' ) );
	}

	public function test_shortq_option_process_timeout_is_7000() {
		$q = $this->freshQueue();
		$this->assertSame( 7000, $q->getShortQ()->getOption( 'process_timeout' ) );
	}

	// -------------------------------------------------------------------------
	// getFilterQueryData
	// -------------------------------------------------------------------------

	/*
	 * getFilterQueryData — shape, date_field and table reference
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

	public function test_getFilterQueryData_date_field_is_ts_added() {
		$q    = $this->freshQueue();
		$data = $this->invokeProtected( $q, 'getFilterQueryData' );
		$this->assertSame( 'ts_added', $data['date_field'] );
	}

	public function test_getFilterQueryData_has_base_query_key() {
		$q    = $this->freshQueue();
		$data = $this->invokeProtected( $q, 'getFilterQueryData' );
		$this->assertArrayHasKey( 'base_query', $data );
	}

	public function test_getFilterQueryData_base_query_references_shortpixel_meta_table() {
		global $wpdb;
		$q    = $this->freshQueue();
		$data = $this->invokeProtected( $q, 'getFilterQueryData' );
		$this->assertStringContainsString( $wpdb->prefix . 'shortpixel_meta', $data['base_query'] );
	}

	public function test_getFilterQueryData_base_query_selects_ID() {
		$q    = $this->freshQueue();
		$data = $this->invokeProtected( $q, 'getFilterQueryData' );
		$this->assertStringContainsString( 'SELECT ID', $data['base_query'] );
	}

	public function test_getFilterQueryData_base_prepare_is_an_empty_array() {
		$q    = $this->freshQueue();
		$data = $this->invokeProtected( $q, 'getFilterQueryData' );
		$this->assertArrayHasKey( 'base_prepare', $data );
		$this->assertIsArray( $data['base_prepare'] );
		$this->assertEmpty( $data['base_prepare'] );
	}

	// -------------------------------------------------------------------------
	// prepareUndoAI
	// -------------------------------------------------------------------------

	/*
	 * prepareUndoAI — intentionally empty for CustomQueue
	 */

	public function test_prepareUndoAI_returns_an_empty_array() {
		$q    = $this->freshQueue();
		$data = $this->invokeProtected( $q, 'prepareUndoAI' );
		$this->assertIsArray( $data );
		$this->assertEmpty( $data );
	}

	// -------------------------------------------------------------------------
	// createNewBulk
	// -------------------------------------------------------------------------

	/*
	 * createNewBulk — returns merged options and strips 'filters'
	 */

	public function test_createNewBulk_returns_an_array() {
		$q      = $this->freshQueue( 'testCustomBulkReturn' . uniqid() );
		$q->resetQueue();
		$result = $q->createNewBulk( array( 'someOption' => true ) );
		$this->assertIsArray( $result );
	}

	public function test_createNewBulk_strips_filters_key_from_returned_options() {
		$q      = $this->freshQueue( 'testCustomBulkFilters' . uniqid() );
		$q->resetQueue();
		$result = $q->createNewBulk( array( 'filters' => array() ) );
		$this->assertArrayNotHasKey( 'filters', $result );
	}

	public function test_createNewBulk_includes_caller_args_in_returned_options() {
		$q      = $this->freshQueue( 'testCustomBulkMerge' . uniqid() );
		$q->resetQueue();
		$result = $q->createNewBulk( array( 'doCustom' => true ) );
		$this->assertArrayHasKey( 'doCustom', $result );
		$this->assertTrue( $result['doCustom'] );
	}

} // class
