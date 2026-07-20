<?php
/**
 * Tests for ShortPixel\Model\Converter\PNGConverter.
 *
 * Focus: the pure-logic surface — constructor state derivation from
 * settings/env, isConvertable's gate, hasTried's checksum comparison,
 * getCheckSum's settings math, checkFileSizeMargin's decision tree, and
 * filterQueue's queue-item mutation.
 *
 * Skipped at the unit level (integration territory):
 *   - convert / convertFile / getPNGImage / isTransparent — need a real
 *     PNG on disk + GD or Imagick installed. Covered by integration.
 *   - restore — updates WP attachment metadata and runs the URL replacer.
 *
 * @package Shortpixel_Image_Optimiser
 */

use ShortPixel\Model\Converter\PNGConverter;
use ShortPixel\Model\Image\ImageMeta;
use ShortPixel\Model\Queue\QueueItem;
use ShortPixel\Model\Image\ImageModel;

class PNGConverterTest extends WP_UnitTestCase {

	/** @var mixed */
	private $savedPng2jpg;
	/** @var mixed */
	private $savedBackupImages;
	/** @var bool */
	private $savedGdInstalled;
	/** @var bool */
	private $savedImagickInstalled;

	public function set_up() {
		parent::set_up();
		$settings                    = \wpSPIO()->settings();
		$env                         = \wpSPIO()->env();
		$this->savedPng2jpg          = $settings->png2jpg;
		$this->savedBackupImages     = $settings->backupImages;
		$this->savedGdInstalled      = $env->is_gd_installed;
		$this->savedImagickInstalled = $env->is_imagick_installed;
	}

	public function tear_down() {
		$settings                     = \wpSPIO()->settings();
		$env                          = \wpSPIO()->env();
		$settings->png2jpg            = $this->savedPng2jpg;
		$settings->backupImages       = $this->savedBackupImages;
		$env->is_gd_installed         = $this->savedGdInstalled;
		$env->is_imagick_installed    = $this->savedImagickInstalled;
		remove_all_filters( 'shortpixel/pngconverter/filesizeMargin' );
		parent::tear_down();
	}

	/**
	 * Build an ImageModel stub scripted for extension / exists /
	 * convertMeta behaviour. Bypasses parent constructors so no filesystem
	 * is touched.
	 */
	private function makeImageStub( array $args = array() ) {
		$defaults = array(
			'extension'         => 'png',
			'exists'            => true,
			'file_format'       => null,
			'is_converted'      => false,
			'did_try'           => false,
		);
		$args = array_merge( $defaults, $args );

		$imageMeta = new ImageMeta();
		if ( ! is_null( $args['file_format'] ) ) {
			$imageMeta->convertMeta()->setFileFormat( $args['file_format'] );
		}
		if ( $args['is_converted'] ) {
			$imageMeta->convertMeta()->setConversionDone();
		}
		if ( $args['did_try'] !== false ) {
			$imageMeta->convertMeta()->setTried( $args['did_try'] );
		}

		return new class( $imageMeta, $args ) {
			public $meta;
			public $stub_args;
			public function __construct( $meta, $args ) {
				$this->meta = $meta;
				$this->stub_args = $args;
			}
			public function getExtension() { return $this->stub_args['extension']; }
			public function exists() { return $this->stub_args['exists']; }
			public function getMeta( $name = false ) {
				if ( $name === false ) return $this->meta;
				return null;
			}
			public function get( $name ) { return null; }
		};
	}

	private function getPrivate( PNGConverter $c, string $prop ) {
		$ref = new ReflectionClass( PNGConverter::class );
		while ( $ref && ! $ref->hasProperty( $prop ) ) {
			$ref = $ref->getParentClass();
		}
		$p = $ref->getProperty( $prop );
		$p->setAccessible( true );
		return $p->getValue( $c );
	}

	private function setPrivate( PNGConverter $c, string $prop, $value ): void {
		$ref = new ReflectionClass( PNGConverter::class );
		while ( $ref && ! $ref->hasProperty( $prop ) ) {
			$ref = $ref->getParentClass();
		}
		$p = $ref->getProperty( $prop );
		$p->setAccessible( true );
		$p->setValue( $c, $value );
	}

	private function invokePrivate( PNGConverter $c, string $method, array $args = array() ) {
		$ref = new ReflectionClass( PNGConverter::class );
		$r   = $ref->getMethod( $method );
		$r->setAccessible( true );
		return $r->invoke( $c, ...$args );
	}

	/*
	 * Constructor — state derivation
	 */

	public function test_constructor_marks_converter_active_when_png2jpg_setting_is_on_and_a_library_is_installed() {
		$settings                       = \wpSPIO()->settings();
		$env                            = \wpSPIO()->env();
		$settings->png2jpg              = 1;
		$env->is_gd_installed           = true;
		$env->is_imagick_installed      = false;

		$c = new PNGConverter( $this->makeImageStub() );

		$this->assertTrue( $this->getPrivate( $c, 'converterActive' ) );
	}

	public function test_constructor_marks_converter_inactive_when_png2jpg_setting_is_zero() {
		\wpSPIO()->settings()->png2jpg = 0;

		$c = new PNGConverter( $this->makeImageStub() );

		$this->assertFalse( $this->getPrivate( $c, 'converterActive' ) );
	}

	public function test_constructor_marks_converter_inactive_and_stores_last_error_when_no_library_is_installed() {
		$settings                       = \wpSPIO()->settings();
		$env                            = \wpSPIO()->env();
		$settings->png2jpg              = 1;
		$env->is_gd_installed           = false;
		$env->is_imagick_installed      = false;

		$c = new PNGConverter( $this->makeImageStub() );

		$this->assertFalse( $this->getPrivate( $c, 'converterActive' ) );
		$this->assertNotNull( $this->getPrivate( $c, 'lastError' ) );
	}

	public function test_constructor_marks_forceConvertTransparent_when_png2jpg_is_two() {
		$settings                    = \wpSPIO()->settings();
		$env                         = \wpSPIO()->env();
		$settings->png2jpg           = 2;
		$env->is_gd_installed        = true;

		$c = new PNGConverter( $this->makeImageStub() );

		$this->assertTrue( $this->getPrivate( $c, 'forceConvertTransparent' ) );
	}

	public function test_constructor_marks_forceConvertTransparent_false_when_png2jpg_is_one() {
		$settings                    = \wpSPIO()->settings();
		$env                         = \wpSPIO()->env();
		$settings->png2jpg           = 1;
		$env->is_gd_installed        = true;

		$c = new PNGConverter( $this->makeImageStub() );

		$this->assertFalse( $this->getPrivate( $c, 'forceConvertTransparent' ) );
	}

	/*
	 * getCheckSum — settings math
	 */

	public function test_getCheckSum_returns_sum_of_png2jpg_and_backupImages_settings() {
		$settings                    = \wpSPIO()->settings();
		$env                         = \wpSPIO()->env();
		$env->is_gd_installed        = true;
		$settings->png2jpg           = 1;
		$settings->backupImages      = 1;

		$c = new PNGConverter( $this->makeImageStub() );
		$this->assertSame( 2, $c->getCheckSum() );

		$settings->png2jpg      = 2;
		$settings->backupImages = 0;
		$c2 = new PNGConverter( $this->makeImageStub() );
		$this->assertSame( 2, $c2->getCheckSum() );
	}

	/*
	 * hasTried — checksum comparison
	 */

	public function test_hasTried_true_when_stored_checksum_matches_current() {
		\wpSPIO()->settings()->png2jpg      = 1;
		\wpSPIO()->settings()->backupImages = 1;
		\wpSPIO()->env()->is_gd_installed   = true;

		$c = new PNGConverter( $this->makeImageStub() );

		$this->assertTrue( $this->invokePrivate( $c, 'hasTried', array( 2 ) ) );
	}

	public function test_hasTried_false_when_stored_checksum_differs_from_current() {
		\wpSPIO()->settings()->png2jpg      = 1;
		\wpSPIO()->settings()->backupImages = 1;
		\wpSPIO()->env()->is_gd_installed   = true;

		$c = new PNGConverter( $this->makeImageStub() );

		$this->assertFalse( $this->invokePrivate( $c, 'hasTried', array( 99 ) ) );
	}

	/*
	 * isConvertable — the gate
	 */

	public function test_isConvertable_false_when_converterActive_is_off() {
		\wpSPIO()->settings()->png2jpg = 0;

		$c = new PNGConverter( $this->makeImageStub( array( 'extension' => 'png' ) ) );
		$this->assertFalse( $c->isConvertable() );
	}

	public function test_isConvertable_false_for_non_png_extension() {
		\wpSPIO()->settings()->png2jpg    = 1;
		\wpSPIO()->env()->is_gd_installed = true;

		$c = new PNGConverter( $this->makeImageStub( array( 'extension' => 'jpg' ) ) );
		$this->assertFalse( $c->isConvertable() );
	}

	public function test_isConvertable_false_when_file_does_not_exist() {
		\wpSPIO()->settings()->png2jpg    = 1;
		\wpSPIO()->env()->is_gd_installed = true;

		$c = new PNGConverter( $this->makeImageStub( array( 'extension' => 'png', 'exists' => false ) ) );
		$this->assertFalse( $c->isConvertable() );
	}

	public function test_isConvertable_false_when_image_is_already_converted() {
		\wpSPIO()->settings()->png2jpg    = 1;
		\wpSPIO()->env()->is_gd_installed = true;

		$c = new PNGConverter( $this->makeImageStub( array( 'extension' => 'png', 'is_converted' => true ) ) );
		$this->assertFalse( $c->isConvertable() );
	}

	public function test_isConvertable_false_when_previous_attempt_matches_current_checksum() {
		$settings                       = \wpSPIO()->settings();
		$env                            = \wpSPIO()->env();
		$settings->png2jpg              = 1;
		$settings->backupImages         = 1;
		$env->is_gd_installed           = true;

		// checksum = png2jpg + backupImages = 2
		$c = new PNGConverter( $this->makeImageStub( array(
			'extension' => 'png',
			'did_try'   => 2,
		) ) );

		$this->assertFalse( $c->isConvertable() );
	}

	public function test_isConvertable_true_on_happy_path() {
		$settings                       = \wpSPIO()->settings();
		$env                            = \wpSPIO()->env();
		$settings->png2jpg              = 1;
		$settings->backupImages         = 1;
		$env->is_gd_installed           = true;

		$c = new PNGConverter( $this->makeImageStub( array( 'extension' => 'png' ) ) );

		$this->assertTrue( $c->isConvertable() );
	}

	/*
	 * checkFileSizeMargin — the decision tree
	 */

	public function test_checkFileSizeMargin_true_when_result_is_smaller_or_equal_to_source() {
		$c = new PNGConverter( $this->makeImageStub() );
		$this->assertTrue( $this->invokePrivate( $c, 'checkFileSizeMargin', array( 1000, 900 ) ) );
		$this->assertTrue( $this->invokePrivate( $c, 'checkFileSizeMargin', array( 1000, 1000 ) ) );
	}

	public function test_checkFileSizeMargin_true_when_source_size_is_zero() {
		$c = new PNGConverter( $this->makeImageStub() );
		$this->assertTrue( $this->invokePrivate( $c, 'checkFileSizeMargin', array( 0, 1000 ) ) );
	}

	/**
	 * Regression sentinel for a7a0f8f9 — the `$fileSize >= $resultSize`
	 * accept-check used to run BEFORE the resultSize==0 reject-check, so
	 * (1000, 0) wrongly returned true and a zero-byte JPG (write failure)
	 * would have replaced the PNG. The zero-result check now runs first.
	 */
	public function test_checkFileSizeMargin_false_when_result_size_is_zero_indicating_write_failure() {
		$c = new PNGConverter( $this->makeImageStub() );
		$this->assertFalse( $this->invokePrivate( $c, 'checkFileSizeMargin', array( 1000, 0 ) ) );
	}

	public function test_checkFileSizeMargin_true_when_result_is_larger_but_within_filter_margin() {
		$c = new PNGConverter( $this->makeImageStub() );

		// Filter allows up to a 10% increase.
		add_filter( 'shortpixel/pngconverter/filesizeMargin', function () { return 10; } );

		// 5% increase — well within the 10% margin.
		$this->assertTrue( $this->invokePrivate( $c, 'checkFileSizeMargin', array( 1000, 1050 ) ) );
	}

	public function test_checkFileSizeMargin_false_when_result_exceeds_filter_margin() {
		$c = new PNGConverter( $this->makeImageStub() );

		add_filter( 'shortpixel/pngconverter/filesizeMargin', function () { return 5; } );

		// 20% increase — well over the 5% margin.
		$this->assertFalse( $this->invokePrivate( $c, 'checkFileSizeMargin', array( 1000, 1200 ) ) );
	}

	public function test_checkFileSizeMargin_true_when_negative_filter_short_circuits_the_check() {
		$c = new PNGConverter( $this->makeImageStub() );

		add_filter( 'shortpixel/pngconverter/filesizeMargin', function () { return -1; } );

		// Even a 100% increase — the negative filter overrides.
		$this->assertTrue( $this->invokePrivate( $c, 'checkFileSizeMargin', array( 1000, 2000 ) ) );
	}

	public function test_checkFileSizeMargin_default_margin_zero_rejects_any_increase() {
		$c = new PNGConverter( $this->makeImageStub() );

		// Default filter is 0 — any positive percentage increase is over the margin.
		$this->assertFalse( $this->invokePrivate( $c, 'checkFileSizeMargin', array( 1000, 1001 ) ) );
	}

	/*
	 * filterQueue — mutates the queue item
	 */

	public function test_filterQueue_replaces_current_action_with_png2jpg_and_reschedules_original_as_next() {
		\wpSPIO()->env()->is_gd_installed = true;

		$c = new PNGConverter( $this->makeImageStub() );

		$item = new QueueItem();
		$item->data()->action = 'optimize';

		$c->filterQueue( $item );

		$this->assertSame( 'png2jpg', $item->data()->action );
		$this->assertSame( array( 'optimize' ), $item->data()->next_actions );
	}

	public function test_filterQueue_records_compressionType_and_smartcrop_on_keep_data() {
		\wpSPIO()->env()->is_gd_installed = true;

		$c = new PNGConverter( $this->makeImageStub() );

		$item = new QueueItem();
		$item->data()->action = 'optimize';

		$c->filterQueue( $item );

		// Both field names must appear on next_keepdata so they survive the
		// data-object reset when the queue transitions to the optimize step.
		$ref = new ReflectionClass( \ShortPixel\Model\Queue\QueueItemData::class );
		$p   = $ref->getProperty( 'next_keepdata' );
		$p->setAccessible( true );
		$stored = $p->getValue( $item->data() );

		$this->assertSame( array( 'compressionType', 'smartcrop' ), $stored );
	}
}
