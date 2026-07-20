<?php
/**
 * Tests for ShortPixel\Controller\CacheController.
 *
 * Scope: storeItem / storeItemObject / getItem / deleteItem / deleteItemObject —
 * everything that is testable against the real WordPress transient layer
 * (which is active in the test harness). Assertions cover the request-level
 * in-memory registry ($cached_items) and actual transient persistence.
 *
 * Out of scope / why:
 * - The `shortpixel/cache/get` and `shortpixel/cache/save` filters are fired
 *   inside the methods; exercising custom filter hooks would require a full
 *   plugin bootstrap and goes beyond unit-testing this class's own logic.
 *
 * @package Shortpixel_Image_Optimiser
 */

use ShortPixel\Controller\CacheController;
use ShortPixel\Model\CacheModel;

class CacheControllerTest extends WP_UnitTestCase {

	/** @var CacheController */
	private $ctrl;

	/** @var string[] Transient keys seeded per test, cleared in tear_down. */
	private $trackedKeys = array();

	public function set_up() {
		parent::set_up();

		// Always start with a clean in-memory registry so tests are order-independent.
		$ref = new ReflectionClass( CacheController::class );
		$p   = $ref->getProperty( 'cached_items' );
		$p->setAccessible( true );
		$p->setValue( null, array() );

		$this->ctrl = new CacheController();
	}

	public function tear_down() {
		foreach ( $this->trackedKeys as $key ) {
			delete_transient( $key );
		}
		$this->trackedKeys = array();

		// Reset registry between tests.
		$ref = new ReflectionClass( CacheController::class );
		$p   = $ref->getProperty( 'cached_items' );
		$p->setAccessible( true );
		$p->setValue( null, array() );

		parent::tear_down();
	}

	private function uniqueKey( string $suffix = '' ): string {
		$key = 'spio_cc_test_' . uniqid() . $suffix;
		$this->trackedKeys[] = $key;
		return $key;
	}

	private function getRegistry(): array {
		$ref = new ReflectionClass( CacheController::class );
		$p   = $ref->getProperty( 'cached_items' );
		$p->setAccessible( true );
		return $p->getValue( null );
	}

	/*
	 * getItem
	 */

	public function test_getItem_returns_cache_model_instance() {
		$key  = $this->uniqueKey();
		$item = $this->ctrl->getItem( $key );
		$this->assertInstanceOf( CacheModel::class, $item );
	}

	public function test_getItem_returns_same_object_on_second_call() {
		$key  = $this->uniqueKey();
		$a    = $this->ctrl->getItem( $key );
		$b    = $this->ctrl->getItem( $key );
		$this->assertSame( $a, $b );
	}

	public function test_getItem_populates_registry() {
		$key = $this->uniqueKey();
		$this->ctrl->getItem( $key );
		$this->assertArrayHasKey( $key, $this->getRegistry() );
	}

	public function test_getItem_loads_existing_transient_value() {
		$key = $this->uniqueKey();
		set_transient( $key, 'hello', HOUR_IN_SECONDS );

		$item = $this->ctrl->getItem( $key );
		$this->assertTrue( $item->exists() );
		$this->assertSame( 'hello', $item->getValue() );
	}

	public function test_getItem_marks_non_existent_when_no_transient() {
		$key  = $this->uniqueKey();
		$item = $this->ctrl->getItem( $key );
		$this->assertFalse( $item->exists() );
	}

	/*
	 * storeItem
	 */

	public function test_storeItem_returns_cache_model() {
		$key    = $this->uniqueKey();
		$result = $this->ctrl->storeItem( $key, 'value' );
		$this->assertInstanceOf( CacheModel::class, $result );
	}

	public function test_storeItem_persists_value_to_transient() {
		$key = $this->uniqueKey();
		$this->ctrl->storeItem( $key, 'stored' );
		$this->assertSame( 'stored', get_transient( $key ) );
	}

	public function test_storeItem_registers_item_in_memory_registry() {
		$key = $this->uniqueKey();
		$this->ctrl->storeItem( $key, 'x' );
		$this->assertArrayHasKey( $key, $this->getRegistry() );
	}

	public function test_storeItem_marks_item_as_existing() {
		$key  = $this->uniqueKey();
		$item = $this->ctrl->storeItem( $key, 'val' );
		$this->assertTrue( $item->exists() );
	}

	public function test_storeItem_stores_array_value() {
		$key     = $this->uniqueKey();
		$payload = array( 'a' => 1, 'b' => array( 'nested' => true ) );
		$this->ctrl->storeItem( $key, $payload );
		$this->assertSame( $payload, get_transient( $key ) );
	}

	public function test_storeItem_overwrites_previously_stored_value() {
		$key = $this->uniqueKey();
		$this->ctrl->storeItem( $key, 'first' );
		$this->ctrl->storeItem( $key, 'second' );
		$this->assertSame( 'second', get_transient( $key ) );
	}

	/*
	 * storeItemObject
	 */

	public function test_storeItemObject_persists_the_model() {
		$key   = $this->uniqueKey();
		$cache = new CacheModel( $key );
		$cache->setValue( 'object-stored' );
		$cache->setExpires( HOUR_IN_SECONDS );

		$this->ctrl->storeItemObject( $cache );

		$this->assertSame( 'object-stored', get_transient( $key ) );
	}

	public function test_storeItemObject_registers_model_in_registry_by_name() {
		$key   = $this->uniqueKey();
		$cache = new CacheModel( $key );
		$cache->setValue( 'reg-test' );
		$cache->setExpires( HOUR_IN_SECONDS );

		$this->ctrl->storeItemObject( $cache );

		$registry = $this->getRegistry();
		$this->assertArrayHasKey( $key, $registry );
		$this->assertSame( $cache, $registry[ $key ] );
	}

	/*
	 * deleteItem
	 */

	public function test_deleteItem_removes_existing_transient() {
		$key = $this->uniqueKey();
		set_transient( $key, 'to-delete', HOUR_IN_SECONDS );

		$this->ctrl->deleteItem( $key );

		$this->assertFalse( get_transient( $key ) );
	}

	public function test_deleteItem_clears_exists_flag_on_model() {
		$key = $this->uniqueKey();
		set_transient( $key, 'exists', HOUR_IN_SECONDS );

		// Prime the registry first via getItem.
		$item = $this->ctrl->getItem( $key );
		$this->assertTrue( $item->exists() );

		$this->ctrl->deleteItem( $key );

		$this->assertFalse( $item->exists() );
	}

	public function test_deleteItem_does_not_throw_when_item_is_absent() {
		$key = $this->uniqueKey(); // never stored
		// Should complete silently without errors.
		$this->ctrl->deleteItem( $key );
		$this->assertFalse( get_transient( $key ) );
	}

	/*
	 * deleteItemObject
	 */

	public function test_deleteItemObject_removes_persisted_transient() {
		$key = $this->uniqueKey();
		set_transient( $key, 'obj-delete', HOUR_IN_SECONDS );

		$cache = new CacheModel( $key );
		$this->assertTrue( $cache->exists() );

		$this->ctrl->deleteItemObject( $cache );

		$this->assertFalse( get_transient( $key ) );
		$this->assertFalse( $cache->exists() );
	}
}
