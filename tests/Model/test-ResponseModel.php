<?php
/**
 * Tests for ShortPixel\Model\ResponseModel.
 *
 * Plain data-holder: public properties, no behaviour beyond the constructor
 * assignment. Tests confirm the constructor contract and that every declared
 * field starts null so producers can distinguish "not set" from an explicit
 * value.
 *
 * @package Shortpixel_Image_Optimiser
 */

use ShortPixel\Model\ResponseModel;

class ResponseModelTest extends WP_UnitTestCase {

	/*
	 * Constructor
	 */

	public function test_constructor_assigns_item_id_and_item_type() {
		$m = new ResponseModel( 42, 'media' );
		$this->assertSame( 42, $m->item_id );
		$this->assertSame( 'media', $m->item_type );
	}

	public function test_constructor_accepts_custom_item_type() {
		$m = new ResponseModel( 7, 'custom' );
		$this->assertSame( 'custom', $m->item_type );
	}

	public function test_constructor_leaves_every_other_field_null() {
		$m = new ResponseModel( 1, 'media' );

		$this->assertNull( $m->fileName );
		$this->assertNull( $m->is_error );
		$this->assertNull( $m->is_done );
		$this->assertNull( $m->apiStatus );
		$this->assertNull( $m->fileStatus );
		$this->assertNull( $m->tries );
		$this->assertNull( $m->images_done );
		$this->assertNull( $m->images_waiting );
		$this->assertNull( $m->images_total );
		$this->assertNull( $m->issue_type );
		$this->assertNull( $m->message );
		$this->assertNull( $m->action );
	}

	/*
	 * Public-property contract — producers set fields directly.
	 */

	public function test_all_declared_public_fields_are_directly_writable() {
		$m = new ResponseModel( 1, 'media' );

		$m->fileName      = 'foo.jpg';
		$m->is_error      = true;
		$m->is_done       = false;
		$m->apiStatus     = 2;
		$m->fileStatus    = -1;
		$m->tries         = 3;
		$m->images_done   = 5;
		$m->images_waiting = 2;
		$m->images_total  = 7;
		$m->issue_type    = 4;
		$m->message       = 'a message';
		$m->action        = 'migrate';

		$this->assertSame( 'foo.jpg', $m->fileName );
		$this->assertTrue( $m->is_error );
		$this->assertFalse( $m->is_done );
		$this->assertSame( 2, $m->apiStatus );
		$this->assertSame( -1, $m->fileStatus );
		$this->assertSame( 3, $m->tries );
		$this->assertSame( 5, $m->images_done );
		$this->assertSame( 2, $m->images_waiting );
		$this->assertSame( 7, $m->images_total );
		$this->assertSame( 4, $m->issue_type );
		$this->assertSame( 'a message', $m->message );
		$this->assertSame( 'migrate', $m->action );
	}
}
