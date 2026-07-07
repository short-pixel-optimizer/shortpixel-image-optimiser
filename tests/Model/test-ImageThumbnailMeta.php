<?php
/**
 * Tests for ShortPixel\Model\Image\ImageThumbnailMeta.
 *
 * Pure data-class CRUD with public properties, so no reflection is needed.
 * The class has one non-trivial behaviour: fromClass and toClass both skip
 * the customImprovement field (which only lives here for CustomImageModel's
 * benefit — see the class docblock).
 *
 * @package Shortpixel_Image_Optimiser
 */

use ShortPixel\Model\Image\ImageThumbnailMeta;

class ImageThumbnailMetaTest extends WP_UnitTestCase {

	/*
	 * Constructor — declared defaults + seeded tsAdded
	 */

	public function test_constructor_seeds_tsAdded_to_current_time() {
		$before = time();
		$m      = new ImageThumbnailMeta();
		$after  = time();

		$this->assertGreaterThanOrEqual( $before, $m->tsAdded );
		$this->assertLessThanOrEqual( $after, $m->tsAdded );
	}

	public function test_constructor_leaves_other_defaults_untouched() {
		$m = new ImageThumbnailMeta();

		$this->assertNull( $m->databaseID );
		$this->assertSame( 0, $m->status );
		$this->assertNull( $m->compressionType );
		$this->assertNull( $m->compressedSize );
		$this->assertNull( $m->originalSize );
		$this->assertFalse( $m->did_keepExif );
		$this->assertFalse( $m->did_cmyk2rgb );
		$this->assertNull( $m->resize );
		$this->assertNull( $m->tsOptimized );
		$this->assertNull( $m->webp );
		$this->assertNull( $m->avif );
		$this->assertNull( $m->file );
		$this->assertNull( $m->customImprovement );
	}

	/*
	 * fromClass — copies known props, ignores unknown, skips customImprovement
	 */

	public function test_fromClass_populates_known_properties() {
		$source                 = new stdClass();
		$source->databaseID     = 42;
		$source->status         = 2;
		$source->compressionType = 1;
		$source->compressedSize = 1234;
		$source->originalSize   = 5678;
		$source->did_keepExif   = true;
		$source->did_cmyk2rgb   = true;
		$source->resize         = true;
		$source->resizeWidth    = 800;
		$source->resizeHeight   = 600;
		$source->resizeType     = 'Cover';
		$source->originalWidth  = 1600;
		$source->originalHeight = 1200;
		$source->tsAdded        = 1000000;
		$source->tsOptimized    = 2000000;
		$source->webp           = 'image.webp';
		$source->avif           = 'image.avif';
		$source->file           = 'unlisted.jpg';

		$m = new ImageThumbnailMeta();
		$m->fromClass( $source );

		$this->assertSame( 42, $m->databaseID );
		$this->assertSame( 2, $m->status );
		$this->assertSame( 1, $m->compressionType );
		$this->assertSame( 1234, $m->compressedSize );
		$this->assertSame( 5678, $m->originalSize );
		$this->assertTrue( $m->did_keepExif );
		$this->assertTrue( $m->did_cmyk2rgb );
		$this->assertTrue( $m->resize );
		$this->assertSame( 800, $m->resizeWidth );
		$this->assertSame( 600, $m->resizeHeight );
		$this->assertSame( 'Cover', $m->resizeType );
		$this->assertSame( 1600, $m->originalWidth );
		$this->assertSame( 1200, $m->originalHeight );
		$this->assertSame( 1000000, $m->tsAdded );
		$this->assertSame( 2000000, $m->tsOptimized );
		$this->assertSame( 'image.webp', $m->webp );
		$this->assertSame( 'image.avif', $m->avif );
		$this->assertSame( 'unlisted.jpg', $m->file );
	}

	public function test_fromClass_skips_customImprovement_field() {
		$source                    = new stdClass();
		$source->customImprovement = 42.5;

		$m = new ImageThumbnailMeta();
		$m->fromClass( $source );

		$this->assertNull( $m->customImprovement );
	}

	public function test_fromClass_ignores_unknown_properties() {
		$source              = new stdClass();
		$source->status      = 2;
		$source->unknown_key = 'ignored';

		$m = new ImageThumbnailMeta();
		$m->fromClass( $source );

		$this->assertSame( 2, $m->status );
		$this->assertObjectNotHasAttribute( 'unknown_key', $m );
	}

	/*
	 * toClass — exports every declared property except customImprovement
	 */

	public function test_toClass_returns_stdClass_with_every_declared_property_except_customImprovement() {
		$m                 = new ImageThumbnailMeta();
		$m->status         = 3;
		$m->compressedSize = 999;
		$m->webp           = 'foo.webp';

		$out = $m->toClass();

		$this->assertInstanceOf( stdClass::class, $out );
		$this->assertSame( 3, $out->status );
		$this->assertSame( 999, $out->compressedSize );
		$this->assertSame( 'foo.webp', $out->webp );
		$this->assertObjectNotHasAttribute( 'customImprovement', $out );
	}

	public function test_toClass_exports_customImprovement_would_have_been_present_but_is_intentionally_skipped() {
		$m                    = new ImageThumbnailMeta();
		$m->customImprovement = 42.5;

		$out = $m->toClass();

		$this->assertObjectNotHasAttribute( 'customImprovement', $out );
	}

	/*
	 * toClass → fromClass round-trip (excluding the deliberately-skipped
	 * customImprovement field).
	 */

	public function test_toClass_then_fromClass_roundtrip_preserves_state() {
		$original                 = new ImageThumbnailMeta();
		$original->databaseID     = 7;
		$original->status         = 2;
		$original->compressionType = 2;
		$original->compressedSize = 111;
		$original->originalSize   = 222;
		$original->did_keepExif   = true;
		$original->resizeWidth    = 400;
		$original->resizeHeight   = 300;
		$original->resizeType     = 'Contain';
		$original->tsOptimized    = 1234567;
		$original->webp           = 'img.webp';
		$original->file           = 'unlisted.jpg';

		$restored = new ImageThumbnailMeta();
		$restored->fromClass( $original->toClass() );

		$this->assertSame( 7, $restored->databaseID );
		$this->assertSame( 2, $restored->status );
		$this->assertSame( 2, $restored->compressionType );
		$this->assertSame( 111, $restored->compressedSize );
		$this->assertSame( 222, $restored->originalSize );
		$this->assertTrue( $restored->did_keepExif );
		$this->assertSame( 400, $restored->resizeWidth );
		$this->assertSame( 300, $restored->resizeHeight );
		$this->assertSame( 'Contain', $restored->resizeType );
		$this->assertSame( 1234567, $restored->tsOptimized );
		$this->assertSame( 'img.webp', $restored->webp );
		$this->assertSame( 'unlisted.jpg', $restored->file );
	}
}
