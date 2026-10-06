<?php
/**
 * Tests for ShortPixel\Model\Image\ImageMeta.
 *
 * Focuses on the behaviour that ImageMeta adds on top of ImageThumbnailMeta:
 * eager instantiation of the convertMeta sub-object, its accessor, and the
 * fromClass() legacy-migration paths (tried_png2jpg and did_png2jpg).
 *
 * The parent-class behaviour (defaults, generic property copy, unknown props)
 * is covered by test-ImageThumbnailMeta.
 *
 * @package Shortpixel_Image_Optimiser
 */

use ShortPixel\Model\Image\ImageMeta;
use ShortPixel\Model\Image\ImageConvertMeta;

class ImageMetaTest extends WP_UnitTestCase {

	/*
	 * Constructor
	 */

	public function test_constructor_seeds_tsAdded_via_parent() {
		$before = time();
		$m      = new ImageMeta();
		$after  = time();

		$this->assertGreaterThanOrEqual( $before, $m->tsAdded );
		$this->assertLessThanOrEqual( $after, $m->tsAdded );
	}

	public function test_constructor_leaves_added_defaults_untouched() {
		$m = new ImageMeta();
		$this->assertNull( $m->errorMessage );
		$this->assertFalse( $m->wasConverted );
	}

	public function test_constructor_eagerly_creates_convertMeta_sub_object() {
		$m = new ImageMeta();
		$this->assertInstanceOf( ImageConvertMeta::class, $m->convertMeta() );
	}

	/*
	 * convertMeta() accessor
	 */

	public function test_convertMeta_returns_same_instance_on_repeated_calls() {
		$m = new ImageMeta();
		$this->assertSame( $m->convertMeta(), $m->convertMeta() );
	}

	/*
	 * fromClass — modern convertMeta payload
	 */

	public function test_fromClass_populates_nested_convertMeta_from_source_object() {
		$convertPayload             = new stdClass();
		$convertPayload->fileFormat = 'png';
		$convertPayload->isConverted = true;

		$source              = new stdClass();
		$source->convertMeta = $convertPayload;

		$m = new ImageMeta();
		$m->fromClass( $source );

		$this->assertSame( 'png', $m->convertMeta()->getFileFormat() );
		$this->assertTrue( $m->convertMeta()->isConverted() );
	}

	public function test_fromClass_removes_convertMeta_from_source_before_delegating_to_parent() {
		$convertPayload             = new stdClass();
		$convertPayload->fileFormat = 'heic';

		$source              = new stdClass();
		$source->convertMeta = $convertPayload;
		$source->status      = 2;

		$m = new ImageMeta();
		$m->fromClass( $source );

		// The parent's fromClass iterates $object; if convertMeta wasn't
		// unset, the parent would try to assign an object onto the (unknown)
		// $this->convertMeta property. This asserts it doesn't happen.
		$this->assertSame( 2, $m->status );
		$this->assertSame( 'heic', $m->convertMeta()->getFileFormat() );
	}

	/*
	 * fromClass — legacy tried_png2jpg path
	 */

	public function test_fromClass_maps_legacy_tried_png2jpg_to_convertMeta_setTried() {
		$source                  = new stdClass();
		$source->tried_png2jpg   = 'checksum-legacy';

		$m = new ImageMeta();
		$m->fromClass( $source );

		$this->assertSame( 'checksum-legacy', $m->convertMeta()->didTry() );
		$this->assertFalse( $m->convertMeta()->isConverted() );
	}

	public function test_fromClass_ignores_legacy_tried_png2jpg_when_falsy() {
		$source                = new stdClass();
		$source->tried_png2jpg = 0;

		$m = new ImageMeta();
		$m->fromClass( $source );

		$this->assertFalse( $m->convertMeta()->didTry() );
	}

	/*
	 * fromClass — legacy did_png2jpg path (only when tried_png2jpg is not truthy)
	 */

	public function test_fromClass_maps_legacy_did_png2jpg_to_convertMeta_conversion_done() {
		$source                = new stdClass();
		$source->did_png2jpg   = true;

		$m = new ImageMeta();
		$m->fromClass( $source );

		$this->assertTrue( $m->convertMeta()->isConverted() );
		$this->assertSame( 'png', $m->convertMeta()->getFileFormat() );
	}

	public function test_fromClass_tried_png2jpg_takes_precedence_over_did_png2jpg() {
		$source                = new stdClass();
		$source->tried_png2jpg = 'tried-value';
		$source->did_png2jpg   = true;

		$m = new ImageMeta();
		$m->fromClass( $source );

		// tried wins the elseif; conversion must NOT be marked done.
		$this->assertSame( 'tried-value', $m->convertMeta()->didTry() );
		$this->assertFalse( $m->convertMeta()->isConverted() );
		$this->assertNull( $m->convertMeta()->getFileFormat() );
	}

	public function test_fromClass_ignores_legacy_did_png2jpg_when_falsy() {
		$source              = new stdClass();
		$source->did_png2jpg = false;

		$m = new ImageMeta();
		$m->fromClass( $source );

		$this->assertFalse( $m->convertMeta()->isConverted() );
		$this->assertNull( $m->convertMeta()->getFileFormat() );
	}

	/*
	 * fromClass — plain properties still delegate to the parent
	 */

	public function test_fromClass_delegates_plain_properties_to_parent() {
		$source                = new stdClass();
		$source->status        = 2;
		$source->errorMessage  = 'a failure';
		$source->wasConverted  = true;

		$m = new ImageMeta();
		$m->fromClass( $source );

		$this->assertSame( 2, $m->status );
		$this->assertSame( 'a failure', $m->errorMessage );
		$this->assertTrue( $m->wasConverted );
	}
}
