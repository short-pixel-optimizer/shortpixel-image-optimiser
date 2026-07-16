<?php
/**
 * Tests for ShortPixel\Model\Queue\QueueItemResult.
 *
 * Data-container with magic __get/__set gated by a fixed schema, plus a
 * JsonSerializable contract that strips uninitialised fields. Every field is
 * protected, so state is inspected via reflection.
 *
 * @package Shortpixel_Image_Optimiser
 */

use ShortPixel\Model\Queue\QueueItemResult;

class QueueItemResultTest extends WP_UnitTestCase {

	private function getPrivate( QueueItemResult $r, string $prop ) {
		$ref = new ReflectionClass( QueueItemResult::class );
		$p   = $ref->getProperty( $prop );
		$p->setAccessible( true );
		return $p->getValue( $r );
	}

	/*
	 * Constructor + declared defaults
	 */

	public function test_constructor_assigns_item_id_and_leaves_other_fields_null_by_default() {
		$r = new QueueItemResult( 42 );

		$this->assertSame( 42, $this->getPrivate( $r, 'item_id' ) );
		$this->assertFalse( $this->getPrivate( $r, 'is_done' ) );
		$this->assertFalse( $this->getPrivate( $r, 'is_error' ) );

		// A representative sample of the "start uninitialised" fields.
		$this->assertNull( $this->getPrivate( $r, 'apiStatus' ) );
		$this->assertNull( $this->getPrivate( $r, 'message' ) );
		$this->assertNull( $this->getPrivate( $r, 'files' ) );
		$this->assertNull( $this->getPrivate( $r, 'improvements' ) );
		$this->assertNull( $this->getPrivate( $r, 'aiData' ) );
	}

	/*
	 * Magic __get — schema-gated reads
	 */

	public function test_get_returns_the_value_of_a_declared_field() {
		$r = new QueueItemResult( 1 );
		$this->assertSame( 1, $r->item_id );
		$this->assertFalse( $r->is_done );
	}

	public function test_get_returns_null_for_unknown_field() {
		$r = new QueueItemResult( 1 );
		$this->assertNull( $r->definitely_not_a_field );
	}

	/*
	 * Magic __set — schema-gated writes
	 */

	public function test_set_writes_to_a_declared_field() {
		$r = new QueueItemResult( 1 );
		$r->message = 'a message';
		$r->is_error = true;
		$r->apiStatus = 2;

		$this->assertSame( 'a message', $this->getPrivate( $r, 'message' ) );
		$this->assertTrue( $this->getPrivate( $r, 'is_error' ) );
		$this->assertSame( 2, $this->getPrivate( $r, 'apiStatus' ) );
	}

	public function test_set_silently_drops_writes_to_unknown_field() {
		$r = new QueueItemResult( 1 );
		$r->definitely_not_a_field = 'ignored';

		$this->assertFalse( property_exists( $r, 'definitely_not_a_field' ) );
	}

	/*
	 * remove — resets a declared field back to null
	 */

	public function test_remove_clears_a_declared_field_back_to_null() {
		$r = new QueueItemResult( 1 );
		$r->message = 'a message';

		$r->remove( 'message' );

		$this->assertNull( $this->getPrivate( $r, 'message' ) );
	}

	public function test_remove_is_a_noop_for_unknown_field() {
		$r = new QueueItemResult( 1 );

		// Should not throw or create a stray property.
		$r->remove( 'not-a-field' );

		$this->assertFalse( property_exists( $r, 'not-a-field' ) );
	}

	/*
	 * forReturn — strips uninitialised fields, keeps set ones
	 */

	public function test_forReturn_strips_null_fields_and_keeps_the_set_ones() {
		$r = new QueueItemResult( 42 );
		$r->message = 'ok';
		$r->apiStatus = 2;

		$obj = $r->forReturn();

		$this->assertObjectHasProperty( 'item_id', $obj );
		$this->assertSame( 42, $obj->item_id );
		$this->assertSame( 'ok', $obj->message );
		$this->assertSame( 2, $obj->apiStatus );

		// is_done + is_error default to false — arrayFilterNullValues keeps them.
		$this->assertFalse( $obj->is_done );
		$this->assertFalse( $obj->is_error );

		// Fields never assigned are stripped.
		$this->assertObjectNotHasProperty( 'files', $obj );
		$this->assertObjectNotHasProperty( 'improvements', $obj );
		$this->assertObjectNotHasProperty( 'aiData', $obj );
	}

	public function test_forReturn_keeps_false_and_zero_and_empty_string() {
		$r = new QueueItemResult( 1 );
		$r->success   = false;
		$r->apiStatus = 0;
		$r->message   = '';

		$obj = $r->forReturn();

		$this->assertFalse( $obj->success );
		$this->assertSame( 0, $obj->apiStatus );
		$this->assertSame( '', $obj->message );
	}

	public function test_forReturn_returns_an_object_not_an_array() {
		$this->assertIsObject( ( new QueueItemResult( 1 ) )->forReturn() );
	}

	/*
	 * JsonSerializable contract
	 */

	public function test_jsonSerialize_delegates_to_forReturn() {
		$r          = new QueueItemResult( 7 );
		$r->message = 'msg';

		$this->assertEquals( $r->forReturn(), $r->jsonSerialize() );
	}

	public function test_json_encode_produces_the_same_stripped_shape_as_forReturn() {
		$r          = new QueueItemResult( 7 );
		$r->message = 'msg';

		$decoded = json_decode( json_encode( $r ) );

		$this->assertSame( 7, $decoded->item_id );
		$this->assertSame( 'msg', $decoded->message );
		$this->assertObjectNotHasProperty( 'files', $decoded );
	}
}
