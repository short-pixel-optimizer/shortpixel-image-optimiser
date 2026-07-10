<?php
/**
 * Tests for ShortPixel\Model\Converter\BMPConverter.
 *
 * BMP conversion is largely delegated to the API pipeline; this converter
 * only owns the isConvertable gate, the constant checksum, an empty-body
 * convert() placeholder, and filterQueue's backup-trigger.
 *
 * Skipped at the unit level (integration territory):
 *   - handleConvertedFilter → runs the URL replacer + wp attachment meta
 *   - restore → updates WP attachment metadata + replacer
 *
 * @package Shortpixel_Image_Optimiser
 */

use ShortPixel\Model\Converter\BMPConverter;
use ShortPixel\Model\Image\ImageMeta;
use ShortPixel\Model\Queue\QueueItem;

class BMPConverterTest extends WP_UnitTestCase {

	/**
	 * Build an ImageModel stub scripted for extension. Bypasses parent
	 * constructors so no filesystem is touched.
	 */
	private function makeImageStub( array $args = array() ) {
		$defaults = array(
			'extension' => 'bmp',
		);
		$args = array_merge( $defaults, $args );

		$imageMeta = new ImageMeta();

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
			public function conversionPrepare( $args ) { return true; }
		};
	}

	/*
	 * Constants
	 */

	public function test_convertable_extensions_are_bmp_only() {
		$this->assertSame( array( 'bmp' ), BMPConverter::CONVERTABLE_EXTENSIONS );
	}

	/*
	 * isConvertable
	 */

	public function test_isConvertable_true_for_bmp() {
		$c = new BMPConverter( $this->makeImageStub( array( 'extension' => 'bmp' ) ) );
		$this->assertTrue( $c->isConvertable() );
	}

	public function test_isConvertable_false_for_other_extensions() {
		foreach ( array( 'png', 'jpg', 'jpeg', 'heic', 'tiff', 'gif' ) as $ext ) {
			$c = new BMPConverter( $this->makeImageStub( array( 'extension' => $ext ) ) );
			$this->assertFalse( $c->isConvertable(), "'$ext' should NOT be convertable via BMPConverter" );
		}
	}

	/*
	 * getCheckSum — constant
	 */

	public function test_getCheckSum_returns_the_fixed_constant_1() {
		$c = new BMPConverter( $this->makeImageStub() );
		$this->assertSame( 1, $c->getCheckSum() );
	}

	/*
	 * convert — no-op placeholder
	 */

	public function test_convert_returns_false() {
		$c = new BMPConverter( $this->makeImageStub() );
		$this->assertFalse( $c->convert() );
	}

	/*
	 * filterQueue — returns the queue item unchanged; the mutation is on
	 * the bound imageModel (backup preparation).
	 */

	public function test_filterQueue_returns_the_queue_item_unchanged() {
		$c = new BMPConverter( $this->makeImageStub() );

		$item = new QueueItem();
		$item->data()->action = 'optimize';

		$result = $c->filterQueue( $item, array( 'debug_active' => true ) );

		$this->assertSame( $item, $result );
		// Action is not rewritten — BMPConverter defers the "wrap in
		// convert_api" behaviour to ApiConverter.
		$this->assertSame( 'optimize', $item->data()->action );
	}

	public function test_filterQueue_does_not_throw_when_args_omit_debug_active() {
		$c = new BMPConverter( $this->makeImageStub() );

		$item = new QueueItem();
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
			$result = $c->filterQueue( $item, array() );
		} finally {
			set_error_handler( $previous );
		}

		$this->assertFalse( $noticed, 'filterQueue raised a notice/warning when debug_active was omitted' );
		$this->assertSame( $item, $result );
	}
}
