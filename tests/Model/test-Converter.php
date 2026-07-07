<?php
/**
 * Tests for ShortPixel\Model\Converter\Converter (abstract base).
 *
 * Uses SPIO_TestConverter — a file-scope concrete stub — to exercise the
 * base's routing / helper logic (getConverter, getConverterByExt via
 * getConverter's happy path, isConverterFor, handleConvertedFilter,
 * unique_file). Stub ImageModels are anonymous classes with the parent
 * chain of constructors bypassed so no filesystem is touched.
 *
 * Skipped at the unit level (integration territory):
 *   - getReplacementPath → needs a live filesystem controller, a writable
 *     directory, and a fully wired setTarget on a concrete subclass
 *   - unique_file's collision-resolution loop → requires real files on
 *     disk (short-circuit branches ARE tested below without touching disk)
 *
 * @package Shortpixel_Image_Optimiser
 */

use ShortPixel\Model\Converter\Converter;
use ShortPixel\Model\Converter\PNGConverter;
use ShortPixel\Model\Converter\ApiConverter;
use ShortPixel\Model\Image\ImageMeta;
use ShortPixel\Model\Queue\QueueItem;
use ShortPixel\Model\File\FileModel;

/**
 * Concrete stub extending the abstract base. Every abstract is a no-op so
 * we can instantiate the class and exercise the concrete methods.
 */
class SPIO_TestConverter extends Converter {
	public function convert( $args = array() ) { return false; }
	public function isConvertable() { return false; }
	public function restore() { return false; }
	public function getCheckSum() { return 0; }
	protected function updateMetaData( $params ) {}
	public function getUpdatedMeta() { return array(); }
	protected function setupReplacer() {}
	protected function setTarget( FileModel $file ) {}
	public function filterQueue( QueueItem $item, $args = array() ) {}
}

class ConverterTest extends WP_UnitTestCase {

	/**
	 * Build a stub ImageModel scripted for extension / type / convertMeta
	 * behaviour. Bypasses the parent constructors so no FS is touched.
	 */
	private function makeImageStub( array $args = array() ) {
		$defaults = array(
			'extension'         => 'png',
			'type'              => 'media',
			'file_format'       => null,
			'is_converted'      => false,
			'has_placeholder'   => false,
			'replacement_base'  => false,
		);
		$args = array_merge( $defaults, $args );

		$imageMeta = new ImageMeta();
		if ( ! is_null( $args['file_format'] ) ) {
			$imageMeta->convertMeta()->setFileFormat( $args['file_format'] );
		}
		if ( $args['has_placeholder'] ) {
			$imageMeta->convertMeta()->setPlaceHolder( true );
		}
		if ( $args['is_converted'] ) {
			$imageMeta->convertMeta()->setConversionDone();
		}
		if ( $args['replacement_base'] !== false ) {
			$imageMeta->convertMeta()->setReplacementImageBase( $args['replacement_base'] );
		}

		return new class( $imageMeta, $args ) {
			public $meta;
			public $stub_args;
			public function __construct( $meta, $args ) {
				$this->meta = $meta;
				$this->stub_args = $args;
			}
			public function getExtension() { return $this->stub_args['extension']; }
			public function get( $name ) {
				if ( $name === 'type' ) return $this->stub_args['type'];
				return null;
			}
			public function getMeta( $name = false ) {
				if ( $name === false ) return $this->meta;
				return null;
			}
		};
	}

	private function getPrivate( $instance, string $prop ) {
		$ref = new ReflectionClass( get_class( $instance ) );
		while ( $ref && ! $ref->hasProperty( $prop ) ) {
			$ref = $ref->getParentClass();
		}
		$p = $ref->getProperty( $prop );
		$p->setAccessible( true );
		return $p->getValue( $instance );
	}

	private function setPrivate( $instance, string $prop, $value ): void {
		$ref = new ReflectionClass( get_class( $instance ) );
		while ( $ref && ! $ref->hasProperty( $prop ) ) {
			$ref = $ref->getParentClass();
		}
		$p = $ref->getProperty( $prop );
		$p->setAccessible( true );
		$p->setValue( $instance, $value );
	}

	private function freshConverter(): SPIO_TestConverter {
		$ref = new ReflectionClass( SPIO_TestConverter::class );
		return $ref->newInstanceWithoutConstructor();
	}

	/*
	 * Constants
	 */

	public function test_convertable_extensions_default_to_png_and_heic() {
		$this->assertSame( array( 'png', 'heic' ), Converter::CONVERTABLE_EXTENSIONS );
	}

	public function test_error_constants_are_negative() {
		$this->assertSame( -1, Converter::ERROR_LIBRARY );
		$this->assertSame( -2, Converter::ERROR_PATHFAIL );
		$this->assertSame( -3, Converter::ERROR_RESULTLARGER );
		$this->assertSame( -4, Converter::ERROR_WRITEERROR );
		$this->assertSame( -5, Converter::ERROR_BACKUPERROR );
		$this->assertSame( -6, Converter::ERROR_TRANSPARENT );
	}

	/*
	 * getConverter — routing by extension + special cases
	 */

	public function test_getConverter_returns_false_for_non_object_input() {
		$this->assertFalse( Converter::getConverter( 'not-an-object' ) );
	}

	public function test_getConverter_returns_PNGConverter_for_png_extension() {
		$item = $this->makeImageStub( array( 'extension' => 'png' ) );
		$this->assertInstanceOf( PNGConverter::class, Converter::getConverter( $item ) );
	}

	public function test_getConverter_returns_ApiConverter_for_heic_extension() {
		$item = $this->makeImageStub( array( 'extension' => 'heic' ) );
		$this->assertInstanceOf( ApiConverter::class, Converter::getConverter( $item ) );
	}

	public function test_getConverter_returns_ApiConverter_for_tiff_extension() {
		$item = $this->makeImageStub( array( 'extension' => 'tiff' ) );
		$this->assertInstanceOf( ApiConverter::class, Converter::getConverter( $item ) );
	}

	public function test_getConverter_returns_ApiConverter_for_bmp_extension() {
		$item = $this->makeImageStub( array( 'extension' => 'bmp' ) );
		$this->assertInstanceOf( ApiConverter::class, Converter::getConverter( $item ) );
	}

	public function test_getConverter_returns_false_for_custom_type_regardless_of_extension() {
		$item = $this->makeImageStub( array( 'extension' => 'png', 'type' => 'custom' ) );
		$this->assertFalse( Converter::getConverter( $item ) );
	}

	public function test_getConverter_returns_false_for_unknown_extension_without_convertMeta_hints() {
		$item = $this->makeImageStub( array( 'extension' => 'gif' ) );
		$this->assertFalse( Converter::getConverter( $item ) );
	}

	public function test_getConverter_falls_back_to_convertMeta_fileFormat_when_placeholder_present() {
		// Live extension is 'jpg' (post-conversion placeholder) but the
		// stored file_format is 'png' — should pick PNGConverter.
		$item = $this->makeImageStub( array(
			'extension'       => 'jpg',
			'file_format'     => 'png',
			'has_placeholder' => true,
			'is_converted'    => false,
		) );

		$this->assertInstanceOf( PNGConverter::class, Converter::getConverter( $item ) );
	}

	public function test_getConverter_returns_false_when_isConverted_and_no_placeholder() {
		$item = $this->makeImageStub( array(
			'extension'    => 'jpg',
			'file_format'  => 'png',
			'is_converted' => true,
		) );

		// Once converted, further routing goes to the "already converted"
		// fallback which returns a converter of the original type. This
		// yields PNGConverter because file_format is 'png'.
		$this->assertInstanceOf( PNGConverter::class, Converter::getConverter( $item ) );
	}

	/*
	 * isConverterFor — extension match + 'api' special case
	 */

	public function test_isConverterFor_true_when_extension_matches_convertMeta_fileFormat() {
		$c = $this->freshConverter();
		$this->setPrivate( $c, 'imageModel', $this->makeImageStub( array(
			'extension'   => 'png',
			'file_format' => 'png',
		) ) );

		$this->assertTrue( $c->isConverterFor( 'png' ) );
	}

	public function test_isConverterFor_false_when_extension_does_not_match() {
		$c = $this->freshConverter();
		$this->setPrivate( $c, 'imageModel', $this->makeImageStub( array(
			'file_format' => 'png',
		) ) );

		$this->assertFalse( $c->isConverterFor( 'heic' ) );
	}

	public function test_isConverterFor_true_for_api_when_class_name_contains_apiconverter() {
		$imageStub = $this->makeImageStub( array( 'extension' => 'heic' ) );
		$c         = new ApiConverter( $imageStub );

		$this->assertTrue( $c->isConverterFor( 'api' ) );
	}

	public function test_isConverterFor_false_for_api_when_class_name_does_not_contain_apiconverter() {
		$imageStub = $this->makeImageStub( array( 'extension' => 'png' ) );
		$c         = new PNGConverter( $imageStub );

		$this->assertFalse( $c->isConverterFor( 'api' ) );
	}

	/*
	 * handleConvertedFilter — base is a pass-through
	 */

	public function test_handleConvertedFilter_returns_input_unchanged_on_base() {
		$c    = $this->freshConverter();
		$data = array( 'foo' => 'bar', 'nested' => array( 1, 2 ) );

		$this->assertSame( $data, $c->handleConvertedFilter( $data ) );
	}

	/*
	 * Constructor — binds the imageModel + records its current extension on convertMeta
	 */

	public function test_constructor_binds_the_imageModel_and_records_its_extension_on_convertMeta() {
		$imageStub = $this->makeImageStub( array( 'extension' => 'png' ) );

		$c = new SPIO_TestConverter( $imageStub );

		$this->assertSame( $imageStub, $this->getPrivate( $c, 'imageModel' ) );
		$this->assertSame( 'png', $imageStub->getMeta()->convertMeta()->getFileFormat() );
	}
}
