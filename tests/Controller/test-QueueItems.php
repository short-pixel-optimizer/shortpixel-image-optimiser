<?php
/**
 * Tests for ShortPixel\Controller\Queue\QueueItems.
 *
 * Covers:
 *   - getEmptyItem(): returns a QueueItem with the given id and type, no imageModel.
 *   - getImageItem(): returns a new QueueItem wrapping the supplied ImageModel on each call.
 *   - getImageItem() always returns a fresh (non-cached) object.
 *   - getImageItemByID(): skipped — requires a live filesystem / DB lookup via wpSPIO()->filesystem().
 *
 * Out of scope:
 *   - getImageItemByID() — calls wpSPIO()->filesystem()->getMediaImage() which needs a
 *     real attachment row in the DB and filesystem access; integration territory.
 *
 * @package Shortpixel_Image_Optimiser
 */

use ShortPixel\Controller\Queue\QueueItems;
use ShortPixel\Model\Queue\QueueItem;
use ShortPixel\Model\Image\ImageModel;

class QueueItemsTest extends WP_UnitTestCase {

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Build a minimal ImageModel stub that exposes get('id') and get('type')
	 * without loading any real attachment.
	 */
	private function makeImageModelStub( int $id, string $type = 'media' ): ImageModel {
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

	private function getPrivateOnItem( QueueItem $q, string $prop ) {
		$ref = new ReflectionClass( QueueItem::class );
		$p   = $ref->getProperty( $prop );
		$p->setAccessible( true );
		return $p->getValue( $q );
	}

	// -------------------------------------------------------------------------
	// getEmptyItem
	// -------------------------------------------------------------------------

	/*
	 * getEmptyItem — lightweight identity-only items used for migrate/removeLegacy
	 */

	public function test_getEmptyItem_returns_a_QueueItem_instance() {
		$item = QueueItems::getEmptyItem( 42, 'media' );
		$this->assertInstanceOf( QueueItem::class, $item );
	}

	public function test_getEmptyItem_sets_the_item_id_correctly() {
		$item = QueueItems::getEmptyItem( 99, 'media' );
		$this->assertSame( 99, $this->getPrivateOnItem( $item, 'item_id' ) );
	}

	public function test_getEmptyItem_leaves_imageModel_null() {
		$item = QueueItems::getEmptyItem( 1, 'media' );
		$this->assertNull( $this->getPrivateOnItem( $item, 'imageModel' ) );
	}

	public function test_getEmptyItem_works_for_custom_type() {
		$item = QueueItems::getEmptyItem( 7, 'custom' );
		$this->assertInstanceOf( QueueItem::class, $item );
		$this->assertSame( 7, $this->getPrivateOnItem( $item, 'item_id' ) );
	}

	public function test_getEmptyItem_always_produces_a_fresh_object_per_call() {
		$a = QueueItems::getEmptyItem( 5, 'media' );
		$b = QueueItems::getEmptyItem( 5, 'media' );
		// Caching code is commented out in production — must NOT be the same object.
		$this->assertNotSame( $a, $b );
	}

	public function test_getEmptyItem_seeds_a_fresh_data_object() {
		$item = QueueItems::getEmptyItem( 3, 'media' );
		$this->assertInstanceOf( \ShortPixel\Model\Queue\QueueItemData::class, $item->data() );
	}

	// -------------------------------------------------------------------------
	// getImageItem
	// -------------------------------------------------------------------------

	/*
	 * getImageItem — wraps an existing ImageModel in a QueueItem
	 */

	public function test_getImageItem_returns_a_QueueItem_instance() {
		$model = $this->makeImageModelStub( 10 );
		$item  = QueueItems::getImageItem( $model );
		$this->assertInstanceOf( QueueItem::class, $item );
	}

	public function test_getImageItem_binds_the_imageModel_on_the_returned_item() {
		$model = $this->makeImageModelStub( 10 );
		$item  = QueueItems::getImageItem( $model );
		$this->assertSame( $model, $this->getPrivateOnItem( $item, 'imageModel' ) );
	}

	public function test_getImageItem_captures_the_model_id_as_item_id() {
		$model = $this->makeImageModelStub( 55 );
		$item  = QueueItems::getImageItem( $model );
		$this->assertSame( 55, $this->getPrivateOnItem( $item, 'item_id' ) );
	}

	public function test_getImageItem_returns_different_objects_on_successive_calls() {
		// Caching path is commented out — every call must return a fresh QueueItem.
		$model = $this->makeImageModelStub( 20 );
		$a     = QueueItems::getImageItem( $model );
		$b     = QueueItems::getImageItem( $model );
		$this->assertNotSame( $a, $b );
	}

	public function test_getImageItem_works_for_custom_type_model() {
		$model = $this->makeImageModelStub( 33, 'custom' );
		$item  = QueueItems::getImageItem( $model );
		$this->assertInstanceOf( QueueItem::class, $item );
		$this->assertSame( 33, $this->getPrivateOnItem( $item, 'item_id' ) );
	}

	public function test_getImageItem_seeds_a_fresh_data_object() {
		$model = $this->makeImageModelStub( 1 );
		$item  = QueueItems::getImageItem( $model );
		$this->assertInstanceOf( \ShortPixel\Model\Queue\QueueItemData::class, $item->data() );
	}

	// -------------------------------------------------------------------------
	// Static $items cache is populated with the correct type keys
	// -------------------------------------------------------------------------

	public function test_static_items_array_has_media_and_custom_keys() {
		$ref = new ReflectionClass( QueueItems::class );
		$p   = $ref->getProperty( 'items' );
		$p->setAccessible( true );
		$items = $p->getValue( null );

		$this->assertArrayHasKey( 'media',  $items );
		$this->assertArrayHasKey( 'custom', $items );
	}

} // class
