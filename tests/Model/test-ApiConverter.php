<?php
/**
 * Tests for ShortPixel\Model\Converter\ApiConverter.
 *
 * Focus: isConvertable's gate (extension list + placeholder-in-progress
 * case), getCheckSum (constant), filterQueue's queue-item mutation, and
 * restore's no-op contract.
 *
 * Skipped at the unit level (integration territory):
 *   - prepareQueue → creates placeholder JPGs on disk + calls
 *     conversionPrepare (backup pipeline)
 *   - handleConverted → copies API-returned tempfiles onto the FS +
 *     updates WP attachment meta + runs the URL replacer
 *
 * @package Shortpixel_Image_Optimiser
 */

use ShortPixel\Model\Converter\ApiConverter;
use ShortPixel\Model\Image\ImageMeta;
use ShortPixel\Model\Queue\QueueItem;
use ShortPixel\Model\Image\ImageModel;

class ApiConverterTest extends WP_UnitTestCase {

	/**
	 * Build an ImageModel stub scripted for extension + convertMeta shape.
	 * Bypasses parent constructors so no filesystem is touched.
	 */
	private function makeImageStub( array $args = array() ) {
		$defaults = array(
			'extension'       => 'heic',
			'file_format'     => null,
			'is_converted'    => false,
			'has_placeholder' => false,
		);
		$args = array_merge( $defaults, $args );

		$imageMeta = new ImageMeta();
		if ( ! is_null( $args['file_format'] ) ) {
			$imageMeta->convertMeta()->setFileFormat( $args['file_format'] );
		}
		if ( $args['is_converted'] ) {
			$imageMeta->convertMeta()->setConversionDone();
		}
		if ( $args['has_placeholder'] ) {
			$imageMeta->convertMeta()->setPlaceHolder( true );
		}

		return new class( $imageMeta, $args ) {
			public $meta;
			public $stub_args;
			public function __construct( $meta, $args ) {
				$this->meta = $meta;
				$this->stub_args = $args;
			}
			public function getExtension() { return $this->stub_args['extension']; }
			public function getMeta( $name = false ) {
				if ( $name === false ) return $this->meta;
				return null;
			}
			public function get( $name ) { return null; }
		};
	}

	/*
	 * Constants
	 */

	public function test_convertable_extensions_include_heic_tiff_tif_bmp() {
		$this->assertSame( array( 'heic', 'tiff', 'tif', 'bmp' ), ApiConverter::CONVERTABLE_EXTENSIONS );
	}

	/*
	 * isConvertable
	 */

	public function test_isConvertable_true_for_heic_extension() {
		$c = new ApiConverter( $this->makeImageStub( array( 'extension' => 'heic' ) ) );
		$this->assertTrue( $c->isConvertable() );
	}

	public function test_isConvertable_true_for_tiff_and_tif_and_bmp_extensions() {
		foreach ( array( 'tiff', 'tif', 'bmp' ) as $ext ) {
			$c = new ApiConverter( $this->makeImageStub( array( 'extension' => $ext ) ) );
			$this->assertTrue( $c->isConvertable(), "'$ext' should be convertable" );
		}
	}

	public function test_isConvertable_true_when_placeholder_is_set_but_not_yet_converted() {
		// jpg extension (post-placeholder-copy) but conversion still in
		// progress — should stay convertable so the API flow can continue.
		$c = new ApiConverter( $this->makeImageStub( array(
			'extension'       => 'jpg',
			'has_placeholder' => true,
			'is_converted'    => false,
		) ) );

		$this->assertTrue( $c->isConvertable() );
	}

	public function test_isConvertable_false_when_already_converted() {
		$c = new ApiConverter( $this->makeImageStub( array(
			'extension'    => 'jpg',
			'is_converted' => true,
		) ) );

		$this->assertFalse( $c->isConvertable() );
	}

	public function test_isConvertable_returns_false_for_unknown_extension_without_placeholder() {
		$c = new ApiConverter( $this->makeImageStub( array(
			'extension'       => 'gif',
			'has_placeholder' => false,
			'is_converted'    => false,
		) ) );

		$this->assertFalse( $c->isConvertable() );
	}

	/*
	 * getCheckSum — constant
	 */

	public function test_getCheckSum_returns_the_fixed_constant_1() {
		$c = new ApiConverter( $this->makeImageStub() );
		$this->assertSame( 1, $c->getCheckSum() );
	}

	/*
	 * filterQueue — mutates the queue item
	 */

	public function test_filterQueue_strips_convertto_from_paramlist() {
		$c = new ApiConverter( $this->makeImageStub() );

		$item = new QueueItem();
		$item->data()->paramlist = array(
			0 => array( 'url' => 'a', 'convertto' => 'webp' ),
			1 => array( 'url' => 'b' ),
		);
		$item->data()->action = 'optimize';

		$c->filterQueue( $item, array( 'debug_active' => true ) );

		$this->assertArrayNotHasKey( 'convertto', $item->data()->paramlist[0] );
		// The other entry survives untouched.
		$this->assertSame( 'b', $item->data()->paramlist[1]['url'] );
	}

	public function test_filterQueue_replaces_action_with_convert_api_and_schedules_the_original_as_next() {
		$c = new ApiConverter( $this->makeImageStub() );

		$item = new QueueItem();
		$item->data()->paramlist = array();
		$item->data()->action = 'optimize';

		$c->filterQueue( $item, array( 'debug_active' => true ) );

		$this->assertSame( 'convert_api', $item->data()->action );
		$this->assertSame( array( 'optimize' ), $item->data()->next_actions );
	}

	public function test_filterQueue_forces_compressionType_to_LOSSLESS() {
		$c = new ApiConverter( $this->makeImageStub() );

		$item = new QueueItem();
		$item->data()->paramlist = array();
		$item->data()->action = 'optimize';
		$item->data()->compressionType = ImageModel::COMPRESSION_LOSSY;

		$c->filterQueue( $item, array( 'debug_active' => true ) );

		$this->assertSame( ImageModel::COMPRESSION_LOSSLESS, $item->data()->compressionType );
	}

	public function test_filterQueue_resets_credit_counts_to_a_single_base_image() {
		$c = new ApiConverter( $this->makeImageStub() );

		$item = new QueueItem();
		$item->data()->paramlist = array();
		$item->data()->action = 'optimize';

		$c->filterQueue( $item, array( 'debug_active' => true ) );

		$counts = $item->data()->counts;
		$this->assertInstanceOf( stdClass::class, $counts );
		$this->assertSame( 1, $counts->baseCount );
		$this->assertSame( 0, $counts->avifCount );
		$this->assertSame( 0, $counts->webpCount );
		$this->assertSame( 1, $counts->creditCount );
	}

	public function test_filterQueue_does_not_throw_when_args_omit_debug_active() {
		$c = new ApiConverter( $this->makeImageStub() );

		$item = new QueueItem();
		$item->data()->paramlist = array();
		$item->data()->action = 'optimize';

		// Sentinel: catch E_NOTICE / E_WARNING at the test level so a
		// silently-swallowed undefined-index (e.g. if the harness's
		// convertNoticesToExceptions isn't in effect) still fails the
		// test loudly. Handler returns true so PHP doesn't chain to the
		// default handler and we keep control of the assertion.
		$noticed = false;
		$previous = set_error_handler( function ( $errno ) use ( &$noticed ) {
			if ( $errno & ( E_NOTICE | E_WARNING | E_USER_NOTICE | E_USER_WARNING ) ) {
				$noticed = true;
			}
			return true;
		} );

		try {
			// Passing an empty args array reproduces the caller path that
			// omits debug_active.
			$c->filterQueue( $item, array() );
		} finally {
			set_error_handler( $previous );
		}

		$this->assertFalse( $noticed, 'filterQueue raised a notice/warning when debug_active was omitted' );
		$this->assertSame( 'convert_api', $item->data()->action );
	}

	/*
	 * restore — currently a no-op (placeholder for the abstract contract)
	 */

	public function test_restore_is_a_noop_returning_void() {
		$c = new ApiConverter( $this->makeImageStub() );
		$this->assertNull( $c->restore() );
	}
}
