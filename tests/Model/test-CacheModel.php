<?php
/**
 * Tests for ShortPixel\Model\CacheModel.
 *
 * Uses real WordPress transients throughout — the harness is bootstrapped
 * with a live DB, so no mocking of get_transient / set_transient is needed.
 * Every test picks a unique transient name to keep runs isolated even under
 * `--filter` / repeated runs.
 *
 * @package Shortpixel_Image_Optimiser
 */

use ShortPixel\Model\CacheModel;

class CacheModelTest extends WP_UnitTestCase {

	private function uniqueKey( string $suffix = '' ): string {
		return 'spio_test_' . uniqid() . $suffix;
	}

	/**
	 * Names of transients each test seeded, cleared in tear_down.
	 * @var string[]
	 */
	private $createdKeys = array();

	private function track( string $key ): string {
		$this->createdKeys[] = $key;
		return $key;
	}

	public function tear_down() {
		foreach ( $this->createdKeys as $key ) {
			delete_transient( $key );
		}
		$this->createdKeys = array();
		parent::tear_down();
	}

	private function setPrivate( CacheModel $m, string $prop, $value ): void {
		$ref = new ReflectionClass( CacheModel::class );
		$p   = $ref->getProperty( $prop );
		$p->setAccessible( true );
		$p->setValue( $m, $value );
	}

	private function getPrivate( CacheModel $m, string $prop ) {
		$ref = new ReflectionClass( CacheModel::class );
		$p   = $ref->getProperty( $prop );
		$p->setAccessible( true );
		return $p->getValue( $m );
	}

	private function invokePrivate( CacheModel $m, string $method, array $args = array() ) {
		$ref = new ReflectionClass( CacheModel::class );
		$r   = $ref->getMethod( $method );
		$r->setAccessible( true );
		return $r->invoke( $m, ...$args );
	}

	/*
	 * Constructor + load — via real transient
	 */

	public function test_constructor_stores_name_and_marks_non_existent_when_no_transient() {
		$key = $this->track( $this->uniqueKey() );

		$m = new CacheModel( $key );

		$this->assertSame( $key, $m->getName() );
		$this->assertFalse( $m->exists() );
		$this->assertNull( $m->getValue() );
	}

	public function test_constructor_loads_existing_transient_value() {
		$key = $this->track( $this->uniqueKey() );
		set_transient( $key, 'stored-value', HOUR_IN_SECONDS );

		$m = new CacheModel( $key );

		$this->assertTrue( $m->exists() );
		$this->assertSame( 'stored-value', $m->getValue() );
	}

	public function test_constructor_loads_arbitrary_transient_shapes() {
		$key = $this->track( $this->uniqueKey() );
		$payload = array( 'a' => 1, 'b' => array( 'nested' => true ) );
		set_transient( $key, $payload, HOUR_IN_SECONDS );

		$m = new CacheModel( $key );

		$this->assertSame( $payload, $m->getValue() );
	}

	/*
	 * Simple getters / setters
	 */

	public function test_setValue_updates_in_memory_only_until_save_is_called() {
		$key = $this->track( $this->uniqueKey() );
		$m   = new CacheModel( $key );

		$m->setValue( 'new-value' );

		$this->assertSame( 'new-value', $m->getValue() );
		// Not yet persisted — transient still absent.
		$this->assertFalse( get_transient( $key ) );
	}

	public function test_setExpires_updates_expiration_used_by_next_save() {
		$m = new CacheModel( $this->track( $this->uniqueKey() ) );
		$m->setExpires( 42 );
		$this->assertSame( 42, $this->getPrivate( $m, 'expires' ) );
	}

	public function test_getName_returns_the_transient_key() {
		$key = $this->track( $this->uniqueKey() );
		$m   = new CacheModel( $key );
		$this->assertSame( $key, $m->getName() );
	}

	/*
	 * save() — persists to a real transient, guards zero-TTL
	 */

	public function test_save_writes_the_value_and_flips_exists_to_true() {
		$key = $this->track( $this->uniqueKey() );
		$m   = new CacheModel( $key );
		$m->setValue( array( 'foo' => 'bar' ) );

		$m->save();

		$this->assertTrue( $m->exists() );
		$this->assertSame( array( 'foo' => 'bar' ), get_transient( $key ) );
	}

	public function test_save_skips_when_expires_is_zero_to_avoid_persistent_transient() {
		$key = $this->track( $this->uniqueKey() );
		$m   = new CacheModel( $key );
		$m->setValue( 'ignored' );
		$m->setExpires( 0 );

		$m->save();

		$this->assertFalse( $m->exists() );
		$this->assertFalse( get_transient( $key ) );
	}

	public function test_save_skips_when_expires_is_negative() {
		$key = $this->track( $this->uniqueKey() );
		$m   = new CacheModel( $key );
		$m->setValue( 'ignored' );
		$m->setExpires( -1 );

		$m->save();

		$this->assertFalse( get_transient( $key ) );
	}

	/*
	 * delete() — clears the transient and the exists flag
	 */

	public function test_delete_removes_the_transient_and_clears_exists() {
		$key = $this->track( $this->uniqueKey() );
		set_transient( $key, 'value', HOUR_IN_SECONDS );

		$m = new CacheModel( $key );
		$this->assertTrue( $m->exists() );

		$m->delete();

		$this->assertFalse( $m->exists() );
		$this->assertFalse( get_transient( $key ) );
	}

	/*
	 * checkExpiration — private; guards against WordPress "hanging transient"
	 * edge case where the timeout option is lost.
	 */

	public function test_checkExpiration_returns_true_when_timeout_option_is_intact() {
		$key = $this->track( $this->uniqueKey() );
		set_transient( $key, 'value', HOUR_IN_SECONDS );

		$m = new CacheModel( $key );

		$this->assertTrue( $this->invokePrivate( $m, 'checkExpiration', array( $key ) ) );
	}

	public function test_checkExpiration_deletes_the_hanging_transient_when_timeout_option_is_missing() {
		// Simulate the "hanging transient" state: value present, timeout option gone.
		$key = $this->track( $this->uniqueKey() );
		set_transient( $key, 'hanging-value', HOUR_IN_SECONDS );
		delete_option( '_transient_timeout_' . $key );

		// Skip when an external object cache is active — the model can't inspect timeouts in that case.
		if ( wp_using_ext_object_cache() ) {
			$this->markTestSkipped( 'External object cache in use; timeout option not tracked.' );
		}

		$m = new CacheModel( $key );
		$this->invokePrivate( $m, 'checkExpiration', array( $key ) );

		$this->assertFalse( $m->exists() );
		$this->assertSame( '', $m->getValue() );
		$this->assertFalse( get_transient( $key ) );
	}
}
