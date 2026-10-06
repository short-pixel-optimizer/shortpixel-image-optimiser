<?php
/**
 * Tests for ShortPixel\Model\Image\ImageConvertMeta.
 *
 * Pure data-class CRUD: no dependencies on wpSPIO(), the filesystem, or the
 * database. All properties are protected, so state is inspected via
 * reflection helpers.
 *
 * @package Shortpixel_Image_Optimiser
 */

use ShortPixel\Model\Image\ImageConvertMeta;

class ImageConvertMetaTest extends WP_UnitTestCase {

	private function getPrivate( ImageConvertMeta $m, string $prop ) {
		$ref = new ReflectionClass( ImageConvertMeta::class );
		$p   = $ref->getProperty( $prop );
		$p->setAccessible( true );
		return $p->getValue( $m );
	}

	private function setPrivate( ImageConvertMeta $m, string $prop, $value ): void {
		$ref = new ReflectionClass( ImageConvertMeta::class );
		$p   = $ref->getProperty( $prop );
		$p->setAccessible( true );
		$p->setValue( $m, $value );
	}

	/*
	 * Defaults
	 */

	public function test_constructor_uses_declared_defaults() {
		$m = new ImageConvertMeta();
		$this->assertNull( $this->getPrivate( $m, 'fileFormat' ) );
		$this->assertFalse( $this->getPrivate( $m, 'isConverted' ) );
		$this->assertFalse( $this->getPrivate( $m, 'placeholder' ) );
		$this->assertFalse( $this->getPrivate( $m, 'replacementImageBase' ) );
		$this->assertFalse( $this->getPrivate( $m, 'triedConversion' ) );
		$this->assertFalse( $this->getPrivate( $m, 'errorReason' ) );
		$this->assertTrue( $this->getPrivate( $m, 'omitBackup' ) );
	}

	/*
	 * isConverted / didTry / setTried
	 */

	public function test_isConverted_false_by_default() {
		$this->assertFalse( ( new ImageConvertMeta() )->isConverted() );
	}

	public function test_didTry_false_by_default() {
		$this->assertFalse( ( new ImageConvertMeta() )->didTry() );
	}

	public function test_setTried_stores_arbitrary_value_and_didTry_returns_it() {
		$m = new ImageConvertMeta();
		$m->setTried( 'checksum-abc123' );
		$this->assertSame( 'checksum-abc123', $m->didTry() );

		$m->setTried( 42 );
		$this->assertSame( 42, $m->didTry() );
	}

	/*
	 * setConversionDone
	 */

	public function test_setConversionDone_marks_converted_and_omits_backup_by_default() {
		$m = new ImageConvertMeta();
		$m->setConversionDone();
		$this->assertTrue( $m->isConverted() );
		$this->assertTrue( $m->omitBackup() );
	}

	public function test_setConversionDone_can_disable_omitBackup() {
		$m = new ImageConvertMeta();
		$m->setConversionDone( false );
		$this->assertTrue( $m->isConverted() );
		$this->assertFalse( $m->omitBackup() );
	}

	/*
	 * setError / getError
	 */

	public function test_getError_false_by_default() {
		$this->assertFalse( ( new ImageConvertMeta() )->getError() );
	}

	public function test_setError_roundtrip() {
		$m = new ImageConvertMeta();
		$m->setError( -42 );
		$this->assertSame( -42, $m->getError() );
	}

	/*
	 * setFileFormat / getFileFormat — "only stored once" contract
	 */

	public function test_getFileFormat_null_by_default() {
		$this->assertNull( ( new ImageConvertMeta() )->getFileFormat() );
	}

	public function test_setFileFormat_stores_first_value() {
		$m = new ImageConvertMeta();
		$m->setFileFormat( 'png' );
		$this->assertSame( 'png', $m->getFileFormat() );
	}

	public function test_setFileFormat_ignores_subsequent_calls() {
		$m = new ImageConvertMeta();
		$m->setFileFormat( 'png' );
		$m->setFileFormat( 'heic' );
		$this->assertSame( 'png', $m->getFileFormat() );
	}

	/*
	 * placeholder
	 */

	public function test_hasPlaceHolder_false_by_default() {
		$this->assertFalse( ( new ImageConvertMeta() )->hasPlaceHolder() );
	}

	public function test_setPlaceHolder_defaults_to_true() {
		$m = new ImageConvertMeta();
		$m->setPlaceHolder();
		$this->assertTrue( $m->hasPlaceHolder() );
	}

	public function test_setPlaceHolder_can_clear() {
		$m = new ImageConvertMeta();
		$m->setPlaceHolder( true );
		$m->setPlaceHolder( false );
		$this->assertFalse( $m->hasPlaceHolder() );
	}

	/*
	 * replacementImageBase
	 */

	public function test_getReplacementImageBase_false_by_default() {
		$this->assertFalse( ( new ImageConvertMeta() )->getReplacementImageBase() );
	}

	public function test_setReplacementImageBase_roundtrip() {
		$m = new ImageConvertMeta();
		$m->setReplacementImageBase( 'my-image-base' );
		$this->assertSame( 'my-image-base', $m->getReplacementImageBase() );

		$m->setReplacementImageBase( false );
		$this->assertFalse( $m->getReplacementImageBase() );
	}

	/*
	 * fromClass — populates known properties, ignores unknown ones
	 */

	public function test_fromClass_populates_known_properties() {
		$source                       = new stdClass();
		$source->fileFormat           = 'png';
		$source->isConverted          = true;
		$source->placeholder          = true;
		$source->replacementImageBase = 'foo';
		$source->triedConversion      = 'checksum';
		$source->errorReason          = -7;
		$source->omitBackup           = false;

		$m = new ImageConvertMeta();
		$m->fromClass( $source );

		$this->assertSame( 'png', $m->getFileFormat() );
		$this->assertTrue( $m->isConverted() );
		$this->assertTrue( $m->hasPlaceHolder() );
		$this->assertSame( 'foo', $m->getReplacementImageBase() );
		$this->assertSame( 'checksum', $m->didTry() );
		$this->assertSame( -7, $m->getError() );
		$this->assertFalse( $m->omitBackup() );
	}

	public function test_fromClass_ignores_unknown_properties() {
		$source                     = new stdClass();
		$source->fileFormat         = 'png';
		$source->this_does_not_exist = 'ignored';

		$m = new ImageConvertMeta();
		$m->fromClass( $source );

		$this->assertSame( 'png', $m->getFileFormat() );
		$this->assertFalse( property_exists( $m, 'this_does_not_exist' ) );
	}

	/*
	 * toClass — round-trip
	 */

	public function test_toClass_returns_stdClass_with_every_declared_property() {
		$m = new ImageConvertMeta();
		$m->setFileFormat( 'png' );
		$m->setConversionDone( false );
		$m->setPlaceHolder( true );
		$m->setReplacementImageBase( 'my-base' );
		$m->setTried( 'chk' );
		$m->setError( -5 );

		$out = $m->toClass();

		$this->assertInstanceOf( stdClass::class, $out );
		$this->assertSame( 'png', $out->fileFormat );
		$this->assertTrue( $out->isConverted );
		$this->assertTrue( $out->placeholder );
		$this->assertSame( 'my-base', $out->replacementImageBase );
		$this->assertSame( 'chk', $out->triedConversion );
		$this->assertSame( -5, $out->errorReason );
		$this->assertFalse( $out->omitBackup );
	}

	public function test_toClass_then_fromClass_roundtrip_preserves_state() {
		$original = new ImageConvertMeta();
		$original->setFileFormat( 'heic' );
		$original->setConversionDone( true );
		$original->setPlaceHolder( true );
		$original->setReplacementImageBase( 'foo' );
		$original->setTried( 123 );
		$original->setError( -12 );

		$restored = new ImageConvertMeta();
		$restored->fromClass( $original->toClass() );

		$this->assertSame( 'heic', $restored->getFileFormat() );
		$this->assertTrue( $restored->isConverted() );
		$this->assertTrue( $restored->hasPlaceHolder() );
		$this->assertSame( 'foo', $restored->getReplacementImageBase() );
		$this->assertSame( 123, $restored->didTry() );
		$this->assertSame( -12, $restored->getError() );
		$this->assertTrue( $restored->omitBackup() );
	}
}
