<?php
/**
 * Tests for ShortPixel\External\Offload\Offloader.
 *
 * Focus areas:
 *   - Singleton contract (getInstance)
 *   - isActive branches — no-offloader (false), wp-offload delegate,
 *     other-name (null "not implemented" contract)
 *   - getOffloadName getter
 *
 * Skipped at the unit level (integration territory — need to mock
 * class_exists / defined for third-party plugins, or need a real
 * as3cf instance):
 *   - __construct                → registers plugins_loaded + as3cf_init hooks
 *   - load                       → depends on checkVirtualLoaders
 *   - checkVirtualLoaders        → checks class_exists / defined at runtime
 *   - initS3Offload              → needs an as3cf instance to delegate to
 *
 * @package Shortpixel_Image_Optimiser
 */

use ShortPixel\External\Offload\Offloader;

class OffloaderTest extends WP_UnitTestCase {

	public function tear_down() {
		// The Offloader singleton is created at file-load time (self-boot at
		// the bottom of Offloader.php) and its state persists across tests.
		// Reset the singleton + the shared offload instance so per-test
		// mutations via setPrivate() don't leak.
		$ref = new ReflectionClass( Offloader::class );

		$inst = $ref->getProperty( 'instance' );
		$inst->setAccessible( true );
		$inst->setValue( null, null );

		$offInst = $ref->getProperty( 'offload_instance' );
		$offInst->setAccessible( true );
		$offInst->setValue( null, null );

		parent::tear_down();
	}

	/*
	 * Reflection helpers
	 */

	private function getPrivate( Offloader $o, string $prop ) {
		$ref = new ReflectionClass( Offloader::class );
		$p   = $ref->getProperty( $prop );
		$p->setAccessible( true );
		return $p->getValue( $o );
	}

	private function setPrivate( Offloader $o, string $prop, $value ): void {
		$ref = new ReflectionClass( Offloader::class );
		$p   = $ref->getProperty( $prop );
		$p->setAccessible( true );
		$p->setValue( $o, $value );
	}

	private function setStatic( string $prop, $value ): void {
		$ref = new ReflectionClass( Offloader::class );
		$p   = $ref->getProperty( $prop );
		$p->setAccessible( true );
		$p->setValue( null, $value );
	}

	/**
	 * Build a fresh Offloader without running the constructor (which
	 * would register the plugins_loaded + as3cf_init hooks).
	 */
	private function freshOffloader(): Offloader {
		$ref = new ReflectionClass( Offloader::class );
		return $ref->newInstanceWithoutConstructor();
	}

	/*
	 * getInstance — singleton contract
	 */

	public function test_getInstance_returns_an_Offloader() {
		$this->assertInstanceOf( Offloader::class, Offloader::getInstance() );
	}

	public function test_getInstance_returns_the_same_instance_on_repeated_calls() {
		$a = Offloader::getInstance();
		$b = Offloader::getInstance();

		$this->assertSame( $a, $b );
	}

	/*
	 * isActive — three branches: null offloadName, wp-offload delegate, other-name null
	 */

	public function test_isActive_returns_false_when_no_offloader_was_detected() {
		$o = $this->freshOffloader();
		// offloadName is null by default (never assigned).

		$this->assertFalse( $o->isActive() );
		// Sentinel: even when caller asks about a specific other offloader,
		// a missing detection short-circuits to false rather than null.
		$this->assertFalse( $o->isActive( 'stack' ) );
	}

	public function test_isActive_returns_null_for_a_non_wp_offload_name_when_an_offloader_is_active() {
		$o = $this->freshOffloader();
		$this->setPrivate( $o, 'offloadName', 'stack' );

		$result = $o->isActive( 'stack' );

		$this->assertNull( $result );
		// Sentinel: null is the documented "not implemented, don't treat as
		// false" contract. Assert type strictly so a bool false regression
		// (which would silently misuse in boolean context) fails loudly.
		$this->assertNotSame( false, $result );
		$this->assertNotSame( true, $result );
	}

	public function test_isActive_delegates_to_offload_instance_when_wp_offload_is_asked_and_detected() {
		$o = $this->freshOffloader();
		$this->setPrivate( $o, 'offloadName', 'wp-offload' );

		// Stub $offload_instance to a class that returns a known isActive() value.
		$stub = new class {
			public function isActive() { return true; }
		};
		$this->setStatic( 'offload_instance', $stub );

		$this->assertTrue( $o->isActive( 'wp-offload' ) );
	}

	/*
	 * getOffloadName — trivial getter
	 */

	public function test_getOffloadName_returns_the_private_offloadName() {
		$o = $this->freshOffloader();
		$this->setPrivate( $o, 'offloadName', 'wp-offload' );

		$this->assertSame( 'wp-offload', $o->getOffloadName() );
	}

	public function test_getOffloadName_returns_null_when_no_offloader_was_detected() {
		$o = $this->freshOffloader();

		$this->assertNull( $o->getOffloadName() );
	}
}
